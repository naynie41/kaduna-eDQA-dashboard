"""Read-only KoboToolbox access shared by the Kobo discovery tools.

Checked against the KPI OpenAPI schema served at {base}/api/v2/schema/ ("KoboToolbox Primary API",
2.0.0 (api_v2), read 31 Oct 2026). The endpoints used, all GET:

  /me/                                            the token's account (only `username` is kept)
  /api/v2/assets/?limit=&start=                   the assets visible to the token
  /api/v2/assets/{uid}/                           one asset: owner__username, deployment_status,
                                                  deployment__active, deployed_version_id,
                                                  deployment__submission_count, effective_permissions
  /api/v2/assets/{uid}/versions/?limit=&start=    every saved version (date_deployed when deployed)
  /api/v2/assets/{uid}/versions/{version uid}/    one version with its content (kobo_forms.py)
  /api/v2/assets/{uid}/hooks/                     REST Services ("external services") visible to
                                                  the token
  /api/v2/assets/{uid}/data/?fields=&limit=&start=  submissions; `fields` keeps only the named
                                                  ones, the page is at most 1000

Two sources with the same methods:
- KoboClient: the API, with the token in the Authorization header ("Token …"). It enforces the
  discovery rules (discovery/README.md) in code:
    * every request passes a guard that allows GET only, to the configured host only; anything
      else raises ReadOnlyViolation before it leaves the machine;
    * the token is never printed, logged or put in an exception message, and never written to a
      file (only the Authorization header carries it);
    * every request waits KOBO_THROTTLE_SECONDS (default 1) after the previous one; a 429 backs
      off (Retry-After when given, else 2, 4, 8 … s, five tries);
    * 401, 403 and 404 stop with a message that says what to check.
- KoboFixtures: the same answers read from saved responses (--offline DIR), for working without
  credentials. Layout, each file as the endpoint returns it:
    me.json, assets.json, asset.json, versions.json, versions/<uid>.json, hooks.json,
    data/page_<start>.json
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import time
from collections.abc import Iterator
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Protocol
from urllib.parse import quote, urlsplit

import httpx
from dotenv import load_dotenv

HERE = Path(__file__).resolve().parent
DATA_DIR = HERE / "data" / "kobo"
OUT_DIR = HERE / "out"
PAGE_SIZE = 1000          # the data endpoint's documented maximum
LIST_SIZE = 100
RETRIES_429 = 5

# Permissions that only read. Anything else an account holds on the asset can change it.
VIEW_ONLY = {"view_asset", "view_submissions", "partial_submissions"}


class ReadOnlyViolation(RuntimeError):
    pass


class KoboError(RuntimeError):
    pass


class KoboForbidden(KoboError):
    """403: the token is valid, but the account may not do this (often expected for a viewer)."""


@dataclass(frozen=True)
class Config:
    url: str
    token: str = field(repr=False)
    asset_uid: str
    throttle: float


def load_config(*, require_asset: bool = True) -> Config:
    load_dotenv(HERE / ".env")
    keys = ["KOBO_BASE_URL", "KOBO_API_TOKEN"] + (["KOBO_ASSET_UID"] if require_asset else [])
    missing = [k for k in keys if not os.environ.get(k, "").strip()]
    if missing:
        sys.exit(f"Missing in discovery/.env: {', '.join(missing)} (see .env.example), "
                 f"or pass --offline DIR")
    url = os.environ["KOBO_BASE_URL"].strip().rstrip("/")
    parts = urlsplit(url)
    if parts.scheme != "https" and parts.hostname not in ("localhost", "127.0.0.1"):
        sys.exit("KOBO_BASE_URL must use https://")
    try:
        throttle = float(os.environ.get("KOBO_THROTTLE_SECONDS", "1") or 1)
    except ValueError:
        sys.exit("KOBO_THROTTLE_SECONDS must be a number")
    return Config(url, os.environ["KOBO_API_TOKEN"].strip(),
                  os.environ.get("KOBO_ASSET_UID", "").strip(), max(throttle, 0.0))


def region(base_url: str) -> str:
    """Where the server is, for Q-17 (data residency)."""
    host = (urlsplit(base_url).hostname or "").lower()
    if host == "kf.kobotoolbox.org":
        return "global server (kf.kobotoolbox.org), hosted by the Kobo organisation, outside Nigeria"
    if host == "eu.kobotoolbox.org":
        return "EU server (eu.kobotoolbox.org), hosted in the European Union"
    if host == "kobo.humanitarianresponse.info":
        return "humanitarian server (kobo.humanitarianresponse.info), hosted for UN OCHA, outside Nigeria"
    return f"self-hosted ({host}): ask the client where it runs"


class KoboSource(Protocol):
    asset_uid: str
    base_url: str

    def me(self) -> dict: ...

    def assets(self) -> list[dict]: ...

    def asset(self) -> dict: ...

    def versions(self) -> list[dict]: ...

    def version(self, uid: str) -> dict: ...

    def hooks(self) -> list[dict]: ...

    def data_pages(self, *, fields: list[str] | None = None,
                   max_pages: int | None = None) -> Iterator[tuple[int, dict]]: ...


def _detail(response: httpx.Response) -> str:
    """Kobo's error detail, if the body is JSON. Never the request or its headers."""
    try:
        body = response.json()
    except ValueError:
        return ""
    return str(body.get("detail", ""))[:200] if isinstance(body, dict) else ""


class KoboClient:
    def __init__(self, cfg: Config) -> None:
        self.cfg = cfg
        self.asset_uid = cfg.asset_uid
        self.base_url = cfg.url
        self._host = urlsplit(cfg.url).hostname
        self._http = httpx.Client(
            base_url=cfg.url,
            timeout=httpx.Timeout(60.0, connect=10.0),
            headers={"User-Agent": "edqa-discovery (read-only)",
                     "Authorization": f"Token {cfg.token}", "Accept": "application/json"},
            event_hooks={"request": [self._guard]},
            follow_redirects=False,
        )
        self._last_request = 0.0

    def _guard(self, request: httpx.Request) -> None:
        if request.method != "GET":
            raise ReadOnlyViolation(
                f"Blocked {request.method} {request.url.path}: discovery tools are read-only")
        if request.url.host != self._host:
            raise ReadOnlyViolation(
                f"Blocked a request to {request.url.host}: the token goes to the configured server only")

    def __enter__(self) -> KoboClient:
        return self

    def __exit__(self, *exc: object) -> None:
        self._http.close()

    @property
    def asset_path(self) -> str:
        return f"/api/v2/assets/{quote(self.asset_uid, safe='')}"

    def get_json(self, path: str, *, params: dict[str, Any] | None = None) -> Any:
        """One GET, throttled; 429 backs off; 401/403/404 raise with what to check."""
        for attempt in range(1, RETRIES_429 + 2):
            wait = self.cfg.throttle - (time.monotonic() - self._last_request)
            if wait > 0:
                time.sleep(wait)
            response = self._http.get(path, params={"format": "json", **(params or {})})
            self._last_request = time.monotonic()
            if response.status_code != 429:
                break
            if attempt > RETRIES_429:
                raise KoboError(f"GET {path}: still HTTP 429 (too many requests) after "
                                f"{RETRIES_429} waits; try again later or raise KOBO_THROTTLE_SECONDS")
            retry_after = response.headers.get("Retry-After", "")
            pause = float(retry_after) if retry_after.isdigit() else 2.0 ** attempt
            print(f"HTTP 429 on {path}: waiting {pause:.0f} s ({attempt}/{RETRIES_429})", file=sys.stderr)
            time.sleep(min(pause, 300))
        status = response.status_code
        if status == 401:
            raise KoboError(f"GET {path}: HTTP 401: the token was not accepted. Check KOBO_API_TOKEN "
                            f"(Account settings → Security → API key) and that KOBO_BASE_URL is the "
                            f"server the account is on")
        if status == 403:
            raise KoboForbidden(f"GET {path}: HTTP 403: the token is valid but this account may not "
                                f"see this ({_detail(response) or 'permission denied'})")
        if status == 404:
            raise KoboError(f"GET {path}: HTTP 404: not found. Check KOBO_ASSET_UID (the id in the "
                            f"project's URL) and that the account can see the project")
        if status >= 400:
            raise KoboError(f"GET {path}: HTTP {status} {_detail(response)}")
        return response.json()

    def _paged(self, path: str, size: int, params: dict[str, Any] | None = None) -> list[dict]:
        out: list[dict] = []
        start = 0
        while True:
            page = self.get_json(path, params={**(params or {}), "start": start, "limit": size})
            out += page.get("results", [])
            if not page.get("next") or not page.get("results"):
                return out
            start += size

    def me(self) -> dict:
        return self.get_json("/me/")

    def assets(self) -> list[dict]:
        return self._paged("/api/v2/assets/", LIST_SIZE)

    def asset(self) -> dict:
        return self.get_json(f"{self.asset_path}/")

    def versions(self) -> list[dict]:
        return self._paged(f"{self.asset_path}/versions/", LIST_SIZE)

    def version(self, uid: str) -> dict:
        """One version with its content (survey, choices, settings): for kobo_forms.py."""
        return self.get_json(f"{self.asset_path}/versions/{quote(uid, safe='')}/")

    def hooks(self) -> list[dict]:
        return self._paged(f"{self.asset_path}/hooks/", LIST_SIZE)

    def data_pages(self, *, fields: list[str] | None = None,
                   max_pages: int | None = None) -> Iterator[tuple[int, dict]]:
        """(start, page) of the data rows, oldest first; with `fields`, only those keys."""
        start = 0
        pages = 0
        while max_pages is None or pages < max_pages:
            params: dict[str, Any] = {"start": start, "limit": PAGE_SIZE, "sort": json.dumps({"_id": 1})}
            if fields:
                params["fields"] = json.dumps(fields)
            page = self.get_json(f"{self.asset_path}/data/", params=params)
            yield start, page
            pages += 1
            if not page.get("next") or len(page.get("results", [])) < PAGE_SIZE:
                return
            start += PAGE_SIZE


class KoboFixtures:
    """The same answers from saved responses (see the module docstring for the layout)."""

    def __init__(self, root: Path, asset_uid: str | None = None) -> None:
        if not (root / "me.json").is_file():
            sys.exit(f"{root}: no me.json; not a Kobo fixture directory")
        self.root = root
        self.base_url = str(self._read("me.json").get("_fixture_base_url", "https://kf.kobotoolbox.org"))
        self.asset_uid = asset_uid if asset_uid is not None else str(self._read("asset.json").get("uid", ""))

    def __enter__(self) -> KoboFixtures:
        return self

    def __exit__(self, *exc: object) -> None:
        return None

    def _read(self, relative: str) -> Any:
        path = self.root / relative
        if not path.is_file():
            raise KoboError(f"{relative}: not in the fixtures")
        return json.loads(path.read_text(encoding="utf-8"))

    def me(self) -> dict:
        return self._read("me.json")

    def assets(self) -> list[dict]:
        return self._read("assets.json").get("results", [])

    def asset(self) -> dict:
        asset = self._read("asset.json")
        if asset.get("uid") != self.asset_uid:
            raise KoboError(f"GET /api/v2/assets/{self.asset_uid}/: HTTP 404: not found (fixtures)")
        return asset

    def versions(self) -> list[dict]:
        return self._read("versions.json").get("results", [])

    def version(self, uid: str) -> dict:
        return self._read(f"versions/{uid}.json")

    def hooks(self) -> list[dict]:
        hooks = self._read("hooks.json")
        if isinstance(hooks, dict) and hooks.get("_fixture_status") == 403:
            raise KoboForbidden("GET hooks/: HTTP 403: the token is valid but this account may not see this")
        return hooks.get("results", [])

    def data_pages(self, *, fields: list[str] | None = None,
                   max_pages: int | None = None) -> Iterator[tuple[int, dict]]:
        files = sorted((self.root / "data").glob("page_*.json"), key=lambda p: int(p.stem.split("_")[1]))
        for n, path in enumerate(files):
            if max_pages is not None and n >= max_pages:
                return
            page = json.loads(path.read_text(encoding="utf-8"))
            if fields:
                page = {**page, "results": [{k: r[k] for k in fields if k in r} for r in page.get("results", [])]}
            yield int(path.stem.split("_")[1]), page


def add_source_args(parser: argparse.ArgumentParser) -> None:
    parser.add_argument("--offline", type=Path, metavar="DIR",
                        help="read saved API responses from DIR instead of calling KoboToolbox "
                             "(e.g. fixtures/kobo)")


def open_source(args: argparse.Namespace, *, require_asset: bool = True) -> KoboClient | KoboFixtures:
    if args.offline:
        return KoboFixtures(args.offline, getattr(args, "asset", None))
    cfg = load_config(require_asset=require_asset)
    if getattr(args, "asset", None):
        cfg = Config(cfg.url, cfg.token, args.asset, cfg.throttle)
    return KoboClient(cfg)


def deployed_versions(versions: list[dict]) -> list[dict]:
    """The versions that were deployed (date_deployed set), oldest first. Kobo keeps every saved
    version; only deployed ones can carry submissions."""
    deployed = [v for v in versions if v.get("date_deployed")]
    return sorted(deployed, key=lambda v: str(v.get("date_deployed")))


def run(main: Any) -> None:
    """Entry point wrapper: client errors as one clean line, never a traceback with headers."""
    try:
        main()
    except (KoboError, ReadOnlyViolation) as e:
        sys.exit(f"Error: {e}")
    except httpx.HTTPError as e:
        sys.exit(f"Network error: {type(e).__name__} (check KOBO_BASE_URL and the connection)")
