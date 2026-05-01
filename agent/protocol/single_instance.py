"""Cross-platform single-instance + URL relay.

acquire_or_relay():
  - If another agent process is already running, send `url` to it and return False
    (caller is the "relay" and should exit).
  - Otherwise, install the IPC endpoint and return True (caller is the "primary"
    and should run the daemon, listening for relayed URLs via on_url).

POSIX: AF_UNIX socket at ~/.config/pideinfo/agent.sock (perms 0600).
Windows: named pipe \\\\.\\pipe\\pideinfo-agent.

The IPC protocol is a single line: the URL plus '\n', terminated by EOF.
"""

from __future__ import annotations

import logging
import os
import platform
import socket
import threading
from pathlib import Path
from typing import Callable

logger = logging.getLogger(__name__)

_IS_WINDOWS = platform.system() == "Windows"
_PIPE_NAME = r"\\.\pipe\pideinfo-agent"


def _socket_path() -> Path:
    base = Path(os.environ.get("XDG_CONFIG_HOME") or Path.home() / ".config") / "pideinfo"
    base.mkdir(parents=True, exist_ok=True)
    return base / "agent.sock"


# -- POSIX implementation ----------------------------------------------------

def _try_relay_posix(url: str) -> bool:
    path = _socket_path()
    if not path.exists():
        return False
    try:
        with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as s:
            s.settimeout(2.0)
            s.connect(str(path))
            s.sendall((url + "\n").encode("utf-8"))
        logger.info("Relayed URL to existing agent via %s", path)
        return True
    except OSError as e:
        logger.warning("Could not relay (stale socket?): %s — removing.", e)
        try:
            path.unlink()
        except FileNotFoundError:
            pass
        return False


def _serve_posix(on_url: Callable[[str], None]) -> None:
    path = _socket_path()
    try:
        path.unlink()
    except FileNotFoundError:
        pass

    server = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    server.bind(str(path))
    os.chmod(path, 0o600)
    server.listen(8)
    logger.info("Listening for relayed URLs on %s", path)

    def loop():
        while True:
            try:
                conn, _ = server.accept()
            except OSError:
                return
            with conn:
                data = conn.recv(2048).decode("utf-8", errors="replace").strip()
                if data:
                    try:
                        on_url(data)
                    except Exception as e:
                        logger.exception("URL handler raised: %s", e)

    threading.Thread(target=loop, daemon=True, name="pideinfo-ipc").start()


# -- Windows implementation --------------------------------------------------

def _try_relay_windows(url: str) -> bool:
    try:
        with open(_PIPE_NAME, "w", encoding="utf-8") as f:
            f.write(url + "\n")
        return True
    except OSError:
        return False


def _serve_windows(on_url: Callable[[str], None]) -> None:
    # Minimal implementation using win32pipe. Falls back to logging if pywin32
    # is not installed — not fatal, the agent still works for polling.
    try:
        import win32pipe  # type: ignore
        import win32file  # type: ignore
    except ImportError:
        logger.warning("pywin32 missing; URL relay disabled on Windows.")
        return

    def loop():
        while True:
            handle = win32pipe.CreateNamedPipe(
                _PIPE_NAME,
                win32pipe.PIPE_ACCESS_INBOUND,
                win32pipe.PIPE_TYPE_MESSAGE | win32pipe.PIPE_READMODE_MESSAGE,
                1, 4096, 4096, 0, None,
            )
            try:
                win32pipe.ConnectNamedPipe(handle, None)
                _, data = win32file.ReadFile(handle, 4096)
                url = data.decode("utf-8", errors="replace").strip()
                if url:
                    try:
                        on_url(url)
                    except Exception as e:
                        logger.exception("URL handler raised: %s", e)
            finally:
                try:
                    win32file.CloseHandle(handle)
                except Exception:
                    pass

    threading.Thread(target=loop, daemon=True, name="pideinfo-ipc").start()


# -- Public API --------------------------------------------------------------

def acquire_or_relay(url: str | None, on_url: Callable[[str], None]) -> bool:
    """Returns True if this process is the primary agent, False if it relayed and should exit."""
    if url is not None:
        relayed = _try_relay_windows(url) if _IS_WINDOWS else _try_relay_posix(url)
        if relayed:
            return False  # caller should exit

    if _IS_WINDOWS:
        _serve_windows(on_url)
    else:
        _serve_posix(on_url)
    return True
