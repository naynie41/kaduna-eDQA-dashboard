"""Read-only KoboToolbox access shared by the Kobo discovery tools.

Two sources with the same methods:
- KoboClient: the KPI v2 API with the API token. It enforces the discovery rules
  (discovery/README.md) in code: every request passes a guard that allows GET only, anything else
  raises ReadOnlyViolation before it leaves the machine; the token is never printed, logged or put
  in an exception message; paged requests are throttled by KOBO_THROTTLE_SECONDS (default 1).
- KoboFixtures: the same answers read from a directory of saved API responses (--offline DIR),
  for working without credentials. Layout, as the API returns each one:
    asset.json                     GET /api/v2/assets/{uid}/
    versions.json                  GET /api/v2/assets/{uid}/versions/  (results, all pages joined)
    versions/<version uid>.json    GET /api/v2/assets/{uid}/versions/{version uid}/  (with content)
    data/page_<start>.json         GET /api/v2/assets/{uid}/data/?start=…  (count, results)

Every tool takes --offline DIR; without it, KOBO_BASE_URL, KOBO_API_TOKEN and KOBO_ASSET_UID come
from discovery/.env.
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
PAGE_SIZE = 1000  # Kobo's largest page


class ReadOnlyViolation(RuntimeError):
    pass


class KoboError(RuntimeError):
    pass


def _guard(request: httpx.Request) -> None:
    if request.method != "GET":
        raise ReadOnlyViolation(
            f"Blocked {request.method} {request.url.path}: discovery tools are read-only")


@dataclass(frozen=True)
class Config:
    url: str
    token: str = field(repr=False)
    asset_uid: str
    throttle: float


def load_config() -> Config:
    load_dotenv(HERE / ".env")
    keys = ["KOBO_BASE_URL", "KOBO_API_TOKEN", "KOBO_ASSET_UID"]
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
    return Config(url, os.environ["KOBO_API_TOKEN"].strip(), os.environ["KOBO_ASSET_UID"].strip(),
                  max(throttle, 0.0))


class KoboSource(Protocol):
    asset_uid: str

    def asset(self) -> dict: ...

    def versions(self) -> list[dict]: ...

    def version(self, uid: str) -> dict: ...

    def data_pages(self, *, max_pages: int | None = None) -> Iterator[tuple[int, dict]]: ...


def _message(response: httpx.Response) -> str:
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
        self._http = httpx.Client(
            base_url=cfg.url,
            timeout=httpx.Timeout(60.0, connect=10.0),
            headers={"User-Agent": "edqa-discovery (read-only)",
                     "Authorization": f"Token {cfg.token}", "Accept": "application/json"},
            event_hooks={"request": [_guard]},
            follow_redirects=False,
        )
        self._last_request = 0.0

    def __enter__(self) -> KoboClient:
        return self

    def __exit__(self, *exc: object) -> None:
        self._http.close()

    @property
    def asset_path(self) -> str:
        return f"/api/v2/assets/{quote(self.asset_uid, safe='')}"

    def get_json(self, path: str, *, params: dict[str, Any] | None = None, throttle: bool = False) -> Any:
        if throttle:
            wait = self.cfg.throttle - (time.monotonic() - self._last_request)
            if wait > 0:
                time.sleep(wait)
        response = self._http.get(path, params={"format": "json", **(params or {})})
        self._last_request = time.monotonic()
        if response.status_code in (401, 403):
            raise KoboError(f"GET {path}: HTTP {response.status_code}: the token was refused, "
                            f"or the account cannot view this asset")
        if response.status_code >= 400:
            raise KoboError(f"GET {path}: HTTP {response.status_code} {_message(response)}")
        return response.json()

    def asset(self) -> dict:
        return self.get_json(f"{self.asset_path}/")

    def versions(self) -> list[dict]:
        out: list[dict] = []
        start = 0
        while True:
            page = self.get_json(f"{self.asset_path}/versions/", params={"start": start, "limit": 100},
                                 throttle=True)
            out += page.get("results", [])
            if not page.get("next"):
                return out
            start += 100

    def version(self, uid: str) -> dict:
        return self.get_json(f"{self.asset_path}/versions/{quote(uid, safe='')}/", throttle=True)

    def data_pages(self, *, max_pages: int | None = None) -> Iterator[tuple[int, dict]]:
        """(start, page) of the data rows, oldest first, throttled between pages."""
        start = 0
        pages = 0
        while max_pages is None or pages < max_pages:
            page = self.get_json(f"{self.asset_path}/data/",
                                 params={"start": start, "limit": PAGE_SIZE,
                                         "sort": json.dumps({"_id": 1})}, throttle=True)
            yield start, page
            pages += 1
            if not page.get("next") or len(page.get("results", [])) < PAGE_SIZE:
                return
            start += PAGE_SIZE


class KoboFixtures:
    """The same answers from saved API responses (see the module docstring for the layout)."""

    def __init__(self, root: Path) -> None:
        if not (root / "asset.json").is_file():
            sys.exit(f"{root}: no asset.json; not a Kobo fixture directory")
        self.root = root
        self.asset_uid = str(self._read("asset.json").get("uid", ""))

    def __enter__(self) -> KoboFixtures:
        return self

    def __exit__(self, *exc: object) -> None:
        return None

    def _read(self, relative: str) -> Any:
        return json.loads((self.root / relative).read_text(encoding="utf-8"))

    def asset(self) -> dict:
        return self._read("asset.json")

    def versions(self) -> list[dict]:
        body = self._read("versions.json")
        return body.get("results", []) if isinstance(body, dict) else body

    def version(self, uid: str) -> dict:
        path = self.root / "versions" / f"{uid}.json"
        if not path.is_file():
            raise KoboError(f"version {uid}: no versions/{uid}.json in the fixtures")
        return json.loads(path.read_text(encoding="utf-8"))

    def data_pages(self, *, max_pages: int | None = None) -> Iterator[tuple[int, dict]]:
        files = sorted((self.root / "data").glob("page_*.json"), key=lambda p: int(p.stem.split("_")[1]))
        for n, path in enumerate(files):
            if max_pages is not None and n >= max_pages:
                return
            yield int(path.stem.split("_")[1]), json.loads(path.read_text(encoding="utf-8"))


def add_source_args(parser: argparse.ArgumentParser) -> None:
    parser.add_argument("--offline", type=Path, metavar="DIR",
                        help="read saved API responses from DIR instead of calling KoboToolbox "
                             "(e.g. fixtures/kobo)")


def open_source(args: argparse.Namespace) -> KoboClient | KoboFixtures:
    return KoboFixtures(args.offline) if args.offline else KoboClient(load_config())


def deployed_versions(asset: dict, versions: list[dict]) -> list[dict]:
    """The versions that were deployed (date_deployed set), oldest first. Kobo lists every saved
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
        sys.exit(f"Network error: {type(e).__name__}")
