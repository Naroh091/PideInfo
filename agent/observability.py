"""Sentry + structured logging bootstrap for the agent.

The DSN is baked into the build at release time (env `SENTRY_DSN`, read by
`Settings`). When empty, Sentry is not initialised and the agent runs the
same as before — only the local rotating log file is added.

Designed to be called as early as possible in `main()` so that pre-auth
crashes (Firefox bootstrap, URL handler registration, single-instance) reach
Sentry. Pre-auth events lack the user's email; `set_user()` attaches it
later when the JWT is loaded.
"""

from __future__ import annotations

import logging
import re
import sys
from logging.handlers import RotatingFileHandler
from pathlib import Path
from typing import TYPE_CHECKING, Any

if TYPE_CHECKING:
    from config import Settings
    from storage.preferences import AgentPreferences

logger = logging.getLogger(__name__)

_INITIALISED = False
_TELEMETRY_ON = False

# Strip JWTs from URLs in events: ?token=eyJhbGc... or Authorization-style
_JWT_RE = re.compile(r"eyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+")


def init(settings: "Settings", prefs: "AgentPreferences") -> None:
    """Initialise logging + Sentry. Idempotent — safe to call after a toggle."""
    global _INITIALISED, _TELEMETRY_ON

    _setup_local_logging(settings.data_dir)

    dsn = settings.sentry_dsn_agent.strip()
    if not dsn:
        logger.info("Sentry: DSN no configurado — telemetría deshabilitada en este build.")
        return
    if not prefs.telemetry_enabled:
        logger.info("Sentry: telemetría desactivada por el usuario.")
        _TELEMETRY_ON = False
        return

    try:
        import sentry_sdk
        from sentry_sdk.integrations.logging import LoggingIntegration
    except Exception as exc:
        logger.warning("Sentry SDK no disponible: %s", exc)
        return

    integrations: list[Any] = [
        LoggingIntegration(level=logging.INFO, event_level=logging.ERROR),
    ]
    # HttpxIntegration is optional; only enable if importable.
    try:
        from sentry_sdk.integrations.httpx import HttpxIntegration  # noqa: F401
        integrations.append(HttpxIntegration())
    except Exception:
        pass

    from version import __version__

    sentry_sdk.init(
        dsn=dsn,
        release=f"pideinfo-agent@{__version__}",
        environment=settings.sentry_environment,
        traces_sample_rate=0.0,
        send_default_pii=False,
        integrations=integrations,
        before_send=_scrub,
        before_breadcrumb=_scrub_breadcrumb,
    )
    _INITIALISED = True
    _TELEMETRY_ON = True
    logger.info(
        "Sentry: telemetría activa (release=pideinfo-agent@%s, environment=%s)",
        __version__, settings.sentry_environment,
    )

    if prefs.user_email:
        set_user(prefs)

    # Cover crashes that escape every try/except.
    _install_excepthook()


def capture_exception(exc: BaseException | None = None, **tags: str) -> None:
    """Explicitly send an exception to Sentry (no-op when telemetry is off).

    Belt-and-suspenders companion to LoggingIntegration: handlers that catch
    via `try/except` and log via `logger.exception(...)` should also call
    this so a single misconfigured logging hierarchy can't lose the event.
    """
    if not _TELEMETRY_ON:
        return
    try:
        import sentry_sdk
        with sentry_sdk.push_scope() as scope:
            for k, v in tags.items():
                if v is not None:
                    scope.set_tag(k, str(v))
            sentry_sdk.capture_exception(exc)
    except Exception:
        pass


def shutdown() -> None:
    """Flush + close the Sentry client. Used when the user disables telemetry."""
    global _INITIALISED, _TELEMETRY_ON
    if not _INITIALISED:
        _TELEMETRY_ON = False
        return
    try:
        import sentry_sdk
        client = sentry_sdk.get_client()
        if client is not None and client.is_active():
            client.close(timeout=2.0)
    except Exception as exc:
        logger.warning("Sentry shutdown falló: %s", exc)
    _INITIALISED = False
    _TELEMETRY_ON = False


def set_user(prefs: "AgentPreferences") -> None:
    """Attach the connected user's email to subsequent events."""
    if not _TELEMETRY_ON:
        return
    try:
        import sentry_sdk
        sentry_sdk.set_user({"email": prefs.user_email or None})
    except Exception:
        pass


# ---------------------------------------------------------------------------
# Local logging — always on, regardless of Sentry DSN.
# ---------------------------------------------------------------------------

def _setup_local_logging(data_dir: Path) -> None:
    """Add a console + rotating file handler to the root logger (idempotent)."""
    root = logging.getLogger()
    if getattr(root, "_pideinfo_handlers_installed", False):
        return

    root.setLevel(logging.INFO)

    fmt = logging.Formatter(
        "%(asctime)s %(levelname)s [%(name)s] %(message)s",
        datefmt="%Y-%m-%dT%H:%M:%S",
    )

    try:
        data_dir.mkdir(parents=True, exist_ok=True)
        file_handler = RotatingFileHandler(
            data_dir / "agent.log",
            maxBytes=5 * 1024 * 1024,
            backupCount=3,
            encoding="utf-8",
        )
        file_handler.setLevel(logging.INFO)
        file_handler.setFormatter(fmt)
        root.addHandler(file_handler)
    except OSError as exc:
        # Disk full / permissions — fall through to console-only.
        logger.warning("No se pudo crear agent.log: %s", exc)

    root._pideinfo_handlers_installed = True  # type: ignore[attr-defined]


# ---------------------------------------------------------------------------
# Scrubbing
# ---------------------------------------------------------------------------

def _scrub(event: dict, _hint: dict) -> dict | None:
    """Strip credentials from outgoing Sentry events."""
    _strip_auth_headers(event.get("request"))
    for entry in event.get("breadcrumbs", {}).get("values", []) or []:
        _scrub_breadcrumb(entry, None)
    if "extra" in event:
        for k, v in list(event["extra"].items()):
            if isinstance(v, str):
                event["extra"][k] = _JWT_RE.sub("<jwt>", v)
    return event


def _scrub_breadcrumb(crumb: dict, _hint: dict | None) -> dict | None:
    data = crumb.get("data") or {}
    if "url" in data and isinstance(data["url"], str):
        data["url"] = _JWT_RE.sub("<jwt>", data["url"])
    headers = data.get("headers") or {}
    if isinstance(headers, dict) and "Authorization" in headers:
        headers["Authorization"] = "<redacted>"
    return crumb


def _strip_auth_headers(request: Any) -> None:
    if not isinstance(request, dict):
        return
    headers = request.get("headers")
    if isinstance(headers, dict) and "Authorization" in headers:
        headers["Authorization"] = "<redacted>"
    if isinstance(request.get("url"), str):
        request["url"] = _JWT_RE.sub("<jwt>", request["url"])


# ---------------------------------------------------------------------------
# Excepthook
# ---------------------------------------------------------------------------

def _install_excepthook() -> None:
    """Forward uncaught main-thread exceptions to Sentry then re-raise."""
    prev_hook = sys.excepthook

    def _hook(exc_type, exc, tb):
        try:
            import sentry_sdk
            sentry_sdk.capture_exception((exc_type, exc, tb))
        except Exception:
            pass
        prev_hook(exc_type, exc, tb)

    sys.excepthook = _hook
