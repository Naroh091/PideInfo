"""Auto-update checker and downloader for PideInfo Agent.

Checks GitHub Releases for tags starting with 'agent-v' and compares against
the running version. If a newer version is found, notifies the user via the
tray menu and offers a one-click download-and-install flow.
"""

from __future__ import annotations

import sys
import tempfile
from pathlib import Path
from typing import Callable

import httpx
from packaging.version import Version
from rich.console import Console

from version import __version__

console = Console()

_RELEASES_URL = "https://api.github.com/repos/Naroh091/pideinfo/releases"
_TAG_PREFIX = "agent-v"

# Platform → expected asset name suffix
_ASSET_SUFFIXES: dict[str, str] = {
    "darwin-arm64": "macos-arm64.dmg",
    "darwin-x86_64": "macos-x64.dmg",
    "win32": "windows-x64-setup.exe",
    "linux": "linux-x64.AppImage",
}


def _current_platform_key() -> str:
    import platform
    if sys.platform == "darwin":
        arch = platform.machine()
        return f"darwin-{arch}"
    return sys.platform


def _asset_suffix() -> str:
    return _ASSET_SUFFIXES.get(_current_platform_key(), "")


async def check_for_update() -> tuple[str, str] | None:
    """Check GitHub Releases for a newer agent version.

    Returns (version_string, download_url) if an update is available, else None.
    """
    try:
        async with httpx.AsyncClient(timeout=10) as client:
            resp = await client.get(_RELEASES_URL, params={"per_page": 20})
            resp.raise_for_status()
            releases = resp.json()
    except Exception as e:
        console.print(f"[dim]Comprobación de actualizaciones fallida: {e}[/]")
        return None

    current = Version(__version__)
    best_version: Version | None = None
    best_download_url: str = ""
    suffix = _asset_suffix()

    for release in releases:
        tag: str = release.get("tag_name", "")
        if not tag.startswith(_TAG_PREFIX):
            continue
        if release.get("draft") or release.get("prerelease"):
            continue
        try:
            ver = Version(tag[len(_TAG_PREFIX):])
        except Exception:
            continue
        if ver <= current:
            continue
        if best_version is not None and ver <= best_version:
            continue

        # Find the matching asset for this platform
        if suffix:
            for asset in release.get("assets", []):
                if asset["name"].endswith(suffix):
                    best_version = ver
                    best_download_url = asset["browser_download_url"]
                    break
        else:
            best_version = ver

    if best_version is None or not best_download_url:
        return None

    return str(best_version), best_download_url


async def download_update(download_url: str, progress_cb: Callable[[int, int], None] | None = None) -> Path:
    """Download the update asset to a temp file. Returns the local path."""
    suffix = Path(download_url).suffix
    tmp = tempfile.NamedTemporaryFile(delete=False, suffix=suffix)
    tmp_path = Path(tmp.name)
    tmp.close()

    async with httpx.AsyncClient(timeout=300, follow_redirects=True) as client:
        async with client.stream("GET", download_url) as resp:
            resp.raise_for_status()
            total = int(resp.headers.get("content-length", 0))
            downloaded = 0
            with open(tmp_path, "wb") as f:
                async for chunk in resp.aiter_bytes(chunk_size=65536):
                    f.write(chunk)
                    downloaded += len(chunk)
                    if progress_cb:
                        progress_cb(downloaded, total)

    return tmp_path


def apply_update(update_path: Path) -> None:
    """Open/run the downloaded update installer and quit this instance."""
    import subprocess

    if sys.platform == "darwin":
        # Open the DMG in Finder; user drags the new .app to /Applications
        subprocess.Popen(["open", str(update_path)])
    elif sys.platform == "win32":
        # Launch the installer; it handles replacing the running app
        subprocess.Popen([str(update_path)])
    else:
        # Linux AppImage: make executable and show a notification
        update_path.chmod(0o755)
        console.print(
            f"[bold yellow]Actualización descargada en {update_path}[/]\n"
            "[dim]Cierra el agente, reemplaza el AppImage actual y reinicia.[/]"
        )
        return

    # Quit the current instance so the installer/new version can take over
    console.print("[yellow]Cerrando agente para aplicar actualización...[/]")
    sys.exit(0)
