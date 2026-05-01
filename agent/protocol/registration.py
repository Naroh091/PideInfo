"""Register/unregister the pideinfo:// URL scheme with the OS.

Linux:   ~/.local/share/applications/pideinfo-agent.desktop + xdg-mime default
macOS:   Requires a .app bundle with CFBundleURLTypes; we only check, do not
         install (out of scope for fase 2a).
Windows: HKEY_CURRENT_USER\\Software\\Classes\\pideinfo + URL Protocol value.

`is_registered()` is best-effort. `register()` returns (success: bool, message: str)
so the tray menu can surface the result.
"""

from __future__ import annotations

import logging
import platform
import shutil
import subprocess
import sys
from pathlib import Path

logger = logging.getLogger(__name__)

_DESKTOP_FILE = Path.home() / ".local/share/applications/pideinfo-agent.desktop"


def _agent_executable() -> str:
    """Return a shell-friendly invocation of the running agent for the desktop entry."""
    # parents[0] = agent/protocol, parents[1] = agent — main.py lives there.
    return shutil.which("pideinfo-agent") or f"{sys.executable} {Path(__file__).resolve().parents[1] / 'main.py'}"


# -- Linux -------------------------------------------------------------------

def _register_linux() -> tuple[bool, str]:
    exe = _agent_executable()
    desktop = f"""[Desktop Entry]
Type=Application
Name=PideInfo Agent
Exec={exe} --url %u
NoDisplay=true
Terminal=false
MimeType=x-scheme-handler/pideinfo;
Categories=Utility;
"""
    _DESKTOP_FILE.parent.mkdir(parents=True, exist_ok=True)
    _DESKTOP_FILE.write_text(desktop, encoding="utf-8")
    try:
        subprocess.run(
            ["xdg-mime", "default", "pideinfo-agent.desktop", "x-scheme-handler/pideinfo"],
            check=True, capture_output=True,
        )
    except (subprocess.CalledProcessError, FileNotFoundError) as e:
        return False, f"xdg-mime falló: {e}"
    try:
        subprocess.run(
            ["update-desktop-database", str(_DESKTOP_FILE.parent)],
            check=False, capture_output=True,
        )
    except FileNotFoundError:
        logger.info("update-desktop-database missing; xdg-mime mapping is in place anyway.")
    return True, f"Handler registrado ({_DESKTOP_FILE})."


def _is_registered_linux() -> bool:
    if not _DESKTOP_FILE.exists():
        return False
    try:
        out = subprocess.run(
            ["xdg-mime", "query", "default", "x-scheme-handler/pideinfo"],
            check=False, capture_output=True, text=True,
        )
        return "pideinfo-agent.desktop" in (out.stdout or "")
    except FileNotFoundError:
        return False


# -- Windows -----------------------------------------------------------------

def _register_windows() -> tuple[bool, str]:
    try:
        import winreg  # type: ignore
    except ImportError:
        return False, "winreg no disponible."
    exe = _agent_executable()
    if shutil.which("pideinfo-agent"):
        cmd = f'"{exe}" --url "%1"'
    else:
        cmd = f'"{sys.executable}" "{Path(__file__).resolve().parents[2] / "main.py"}" --url "%1"'
    try:
        with winreg.CreateKey(winreg.HKEY_CURRENT_USER, r"Software\Classes\pideinfo") as k:
            winreg.SetValueEx(k, None, 0, winreg.REG_SZ, "URL:PideInfo Protocol")
            winreg.SetValueEx(k, "URL Protocol", 0, winreg.REG_SZ, "")
        with winreg.CreateKey(winreg.HKEY_CURRENT_USER, r"Software\Classes\pideinfo\shell\open\command") as k:
            winreg.SetValueEx(k, None, 0, winreg.REG_SZ, cmd)
        return True, "Handler registrado en HKEY_CURRENT_USER."
    except OSError as e:
        return False, f"Registro falló: {e}"


def _is_registered_windows() -> bool:
    try:
        import winreg  # type: ignore
        with winreg.OpenKey(winreg.HKEY_CURRENT_USER, r"Software\Classes\pideinfo"):
            return True
    except (ImportError, OSError):
        return False


# -- Public ------------------------------------------------------------------

def register() -> tuple[bool, str]:
    sysname = platform.system()
    if sysname == "Linux":
        return _register_linux()
    if sysname == "Windows":
        return _register_windows()
    if sysname == "Darwin":
        return False, "macOS requiere bundle .app — no implementado en fase 2a."
    return False, f"Sistema no soportado: {sysname}"


def is_registered() -> bool:
    sysname = platform.system()
    if sysname == "Linux":
        return _is_registered_linux()
    if sysname == "Windows":
        return _is_registered_windows()
    return False
