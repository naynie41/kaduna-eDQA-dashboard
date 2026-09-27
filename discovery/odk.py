"""Read-only ODK Central client shared by the discovery tools.

Enforces the discovery rules (discovery/README.md) in code, not just by convention:
- Every request passes a guard that allows GET, plus POST /v1/sessions to log in. Anything
  else raises ReadOnlyViolation before it leaves the machine. There is no logout call.
- The password and session token are never printed, logged or put in an exception message.
- Paged requests are throttled by ODK_THROTTLE_SECONDS (default 1).
"""

from __future__ import annotations

import os
import sys
import time
from collections.abc import Iterator
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any
from urllib.parse import quote, urlsplit

import httpx
from dotenv import load_dotenv

HERE = Path(__file__).resolve().parent
DATA_DIR = HERE / "data"
OUT_DIR = HERE / "out"
LOGIN_PATH = "/v1/sessions"


class ReadOnlyViolation(RuntimeError):
    pass


class OdkError(RuntimeError):
    pass


def _guard(request: httpx.Request) -> None:
    if request.method == "GET":
        return
    if request.method == "POST" and request.url.path.rstrip("/").endswith(LOGIN_PATH):
        return
    raise ReadOnlyViolation(
        f"Blocked {request.method} {request.url.path}: discovery tools are read-only")


@dataclass(frozen=True)
class Config:
    url: str
    email: str
    password: str = field(repr=False)
    project_id: str
    form_id: str
    throttle: float


def load_config(*, require_form: bool = True) -> Config:
    load_dotenv(HERE / ".env")
    keys = ["ODK_CENTRAL_URL", "ODK_CENTRAL_EMAIL", "ODK_CENTRAL_PASSWORD", "ODK_PROJECT_ID"]
    if require_form:
        keys.append("ODK_FORM_ID")
    missing = [k for k in keys if not os.environ.get(k, "").strip()]
    if missing:
        sys.exit(f"Missing in discovery/.env: {', '.join(missing)} (see .env.example)")

    url = os.environ["ODK_CENTRAL_URL"].strip().rstrip("/")
    parts = urlsplit(url)
    local = parts.hostname in ("localhost", "127.0.0.1")
    if parts.scheme != "https" and not local:
        sys.exit("ODK_CENTRAL_URL must use https://")
    try:
        throttle = float(os.environ.get("ODK_THROTTLE_SECONDS", "1") or 1)
    except ValueError:
        sys.exit("ODK_THROTTLE_SECONDS must be a number")
    return Config(
        url=url,
        email=os.environ["ODK_CENTRAL_EMAIL"].strip(),
        password=os.environ["ODK_CENTRAL_PASSWORD"],
        project_id=os.environ["ODK_PROJECT_ID"].strip(),
        form_id=os.environ.get("ODK_FORM_ID", "").strip(),
        throttle=max(throttle, 0.0),
    )


def _message(response: httpx.Response) -> str:
    """Central's error message, if the body is its JSON error shape. Never the request."""
    try:
        body = response.json()
    except ValueError:
        return ""
    return str(body.get("message", ""))[:200] if isinstance(body, dict) else ""


def version_segment(version: str) -> str:
    """Central addresses a blank form version as '___'."""
    return quote(version or "___", safe="")


class OdkClient:
    def __init__(self, cfg: Config) -> None:
        self.cfg = cfg
        self._http = httpx.Client(
            base_url=cfg.url,
            timeout=httpx.Timeout(60.0, connect=10.0),
            headers={"User-Agent": "edqa-discovery (read-only)"},
            event_hooks={"request": [_guard]},
            follow_redirects=False,
        )
        self._token: str | None = None
        self._last_request = 0.0

    def __enter__(self) -> OdkClient:
        return self

    def __exit__(self, *exc: object) -> None:
        # Deliberately no DELETE /v1/sessions/current: the token simply expires.
        self._token = None
        self._http.close()

    # ---- paths

    @property
    def project_path(self) -> str:
        return f"/v1/projects/{quote(self.cfg.project_id, safe='')}"

    @property
    def form_path(self) -> str:
        return f"{self.project_path}/forms/{quote(self.cfg.form_id, safe='')}"

    # ---- requests

    def _login(self) -> None:
        response = self._http.post(
            LOGIN_PATH, json={"email": self.cfg.email, "password": self.cfg.password})
        if response.status_code != 200:
            raise OdkError(f"Login failed: HTTP {response.status_code} {_message(response)}")
        token = response.json().get("token")
        if not token:
            raise OdkError("Login succeeded but no session token was returned")
        self._token = token

    def get(self, path: str, *, params: dict[str, Any] | None = None,
            headers: dict[str, str] | None = None, auth: bool = True,
            throttle: bool = False) -> httpx.Response:
        if throttle:
            wait = self.cfg.throttle - (time.monotonic() - self._last_request)
            if wait > 0:
                time.sleep(wait)
        for attempt in (1, 2):
            h = dict(headers or {})
            if auth:
                if self._token is None:
                    self._login()
                h["Authorization"] = f"Bearer {self._token}"
            response = self._http.get(path, params=params, headers=h)
            self._last_request = time.monotonic()
            if response.status_code == 401 and auth and attempt == 1:
                self._token = None  # expired session: log in once more
                continue
            return response
        return response

    def get_json(self, path: str, **kwargs: Any) -> Any:
        response = self.get(path, **kwargs)
        if response.status_code >= 400:
            raise OdkError(f"GET {path}: HTTP {response.status_code} {_message(response)}")
        return response.json()

    def odata_pages(self, *, top: int, skip: int, filter_: str | None) -> Iterator[tuple[int, dict]]:
        """(skip, page) for the form's Submissions entity set, throttled between pages."""
        path = f"{self.form_path}.svc/Submissions"
        while True:
            params: dict[str, Any] = {"$top": top, "$skip": skip, "$count": "true"}
            if filter_:
                params["$filter"] = filter_
            page = self.get_json(path, params=params, throttle=True)
            yield skip, page
            if len(page.get("value", [])) < top:
                return
            skip += top


def run(main: Any) -> None:
    """Entry point wrapper: turn client errors into one clean line, never a traceback with headers."""
    try:
        main()
    except (OdkError, ReadOnlyViolation) as e:
        sys.exit(f"Error: {e}")
    except httpx.HTTPError as e:
        sys.exit(f"Network error: {type(e).__name__}: {e}")
