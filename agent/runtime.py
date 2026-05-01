"""Runtime helpers for both development and frozen (PyInstaller) environments."""

from __future__ import annotations

import os
import queue
import re
import subprocess
import sys
import threading
from pathlib import Path

from rich.console import Console

console = Console()


def is_frozen() -> bool:
    """Return True if running inside a PyInstaller bundle."""
    return getattr(sys, "frozen", False)


def get_resource_path(relative_path: str) -> Path:
    """Get path to a bundled resource, works both in dev and frozen (PyInstaller) mode."""
    if is_frozen():
        base = Path(sys._MEIPASS)  # type: ignore[attr-defined]
    else:
        base = Path(__file__).parent
    return base / relative_path


def get_playwright_browsers_dir() -> Path:
    """Return the platform-appropriate directory for Playwright browser binaries."""
    if sys.platform == "darwin":
        return Path.home() / "Library" / "Application Support" / "PideInfo Agent" / "playwright-browsers"
    elif sys.platform == "win32":
        local_app_data = os.environ.get("LOCALAPPDATA", Path.home() / "AppData" / "Local")
        return Path(local_app_data) / "PideInfo Agent" / "playwright-browsers"
    else:
        return Path.home() / ".local" / "share" / "pideinfo-agent" / "playwright-browsers"


def apply_baked_env() -> None:
    """Apply build-time-baked env vars (Sentry DSN, environment, etc).

    The CI build writes `agent/_baked_env.py` with a `BAKED_ENV: dict[str, str]`
    constant; we `setdefault` each value so a real env var or `.env` entry
    still wins for developers running from source.
    """
    try:
        from _baked_env import BAKED_ENV  # type: ignore[import-not-found]
    except ImportError:
        return
    for key, value in BAKED_ENV.items():
        if value:
            os.environ.setdefault(key, value)


def setup_playwright_env() -> None:
    """Set PLAYWRIGHT_BROWSERS_PATH so Playwright stores/finds Firefox in a persistent location.

    Must be called early, before Playwright is imported anywhere.
    """
    browsers_dir = get_playwright_browsers_dir()
    browsers_dir.mkdir(parents=True, exist_ok=True)
    os.environ["PLAYWRIGHT_BROWSERS_PATH"] = str(browsers_dir)


def _firefox_is_installed() -> bool:
    """Check whether Firefox has been fully downloaded to PLAYWRIGHT_BROWSERS_PATH.

    Verifies the executable exists, not just the wrapper directory — an
    interrupted download leaves `firefox-XXXX/` empty, which would fool a
    glob-only check and cause `launch_persistent_context` to fail with
    "Executable doesn't exist".
    """
    browsers_path = os.environ.get("PLAYWRIGHT_BROWSERS_PATH")
    if not browsers_path:
        return False
    base = Path(browsers_path)
    if not base.exists():
        return False
    for ff_dir in base.glob("firefox-*"):
        # Per-platform layout used by the Playwright bundle:
        #   macOS:   firefox-XXXX/firefox/Nightly.app/Contents/MacOS/firefox
        #   Linux:   firefox-XXXX/firefox/firefox
        #   Windows: firefox-XXXX/firefox/firefox.exe
        candidates = [
            ff_dir / "firefox" / "Nightly.app" / "Contents" / "MacOS" / "firefox",
            ff_dir / "firefox" / "firefox",
            ff_dir / "firefox" / "firefox.exe",
        ]
        if any(c.exists() for c in candidates):
            return True
    return False


def ensure_firefox() -> None:
    """Ensure Playwright's Firefox browser is available. Downloads if missing.

    In a packaged app, Firefox is not bundled — it is downloaded on first run
    to keep the installer size reasonable.
    Shows a GUI progress modal when a display is available.
    """
    if _firefox_is_installed():
        return

    console.print("[bold yellow]Descargando navegador (solo la primera vez)...[/]")
    returncode = _install_firefox_with_progress()

    if returncode != 0:
        raise RuntimeError("No se pudo descargar el navegador (playwright install firefox falló).")

    console.print("[green]Navegador descargado correctamente[/]")


# ---------------------------------------------------------------------------
# Progress window
# ---------------------------------------------------------------------------

def _install_firefox_with_progress() -> int:
    """Run `playwright install firefox` in a thread and show a GUI progress modal.

    Returns the subprocess return code.
    """
    from playwright._impl._driver import compute_driver_executable, get_driver_env

    node_path, cli_path = compute_driver_executable()
    env = get_driver_env()

    progress_q: queue.Queue[tuple[str, object, str]] = queue.Queue()

    def _run_install() -> None:
        proc = subprocess.Popen(
            [node_path, cli_path, "install", "firefox"],
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            env=env,
            bufsize=0,
        )

        buf = b""
        assert proc.stdout is not None
        while True:
            chunk = proc.stdout.read(256)
            if not chunk:
                break
            buf += chunk
            # Playwright writes progress with \r (overwrite) and \n (new line)
            parts = re.split(b"[\r\n]", buf)
            buf = parts[-1]  # keep incomplete trailing fragment
            for raw in parts[:-1]:
                line = raw.decode("utf-8", errors="replace").strip()
                if not line:
                    continue
                # "50.2 / 148.9 Mb" → compute percentage
                m_frac = re.search(r"([\d.]+)\s*/\s*([\d.]+)\s*[Mm]b", line, re.IGNORECASE)
                if m_frac:
                    done, total = float(m_frac.group(1)), float(m_frac.group(2))
                    pct = int(done / total * 100) if total else 0
                    progress_q.put(("progress", pct, line))
                    continue
                # bare "75%" anywhere in the line
                m_pct = re.search(r"\b(\d{1,3})%", line)
                if m_pct:
                    progress_q.put(("progress", int(m_pct.group(1)), line))
                    continue
                progress_q.put(("text", None, line))

        proc.wait()
        progress_q.put(("done", proc.returncode, ""))

    t = threading.Thread(target=_run_install, daemon=True, name="firefox-install")
    t.start()

    return _run_progress_window(progress_q)


def _run_progress_window(progress_q: "queue.Queue[tuple[str, object, str]]") -> int:
    """Show a Tkinter progress modal fed by *progress_q*.

    Falls back to a blocking console wait when Tkinter is unavailable.
    Returns the subprocess return code.
    """
    try:
        import tkinter as tk
        from tkinter import ttk
    except Exception:
        return _wait_headless(progress_q)

    try:
        root = tk.Tk()
    except Exception:
        return _wait_headless(progress_q)

    root.title("PideInfo Agent")
    root.geometry("440x160")
    root.resizable(False, False)
    root.attributes("-topmost", True)
    # Prevent closing the window mid-download
    root.protocol("WM_DELETE_WINDOW", lambda: None)
    root.update_idletasks()
    x = (root.winfo_screenwidth() - 440) // 2
    y = (root.winfo_screenheight() - 160) // 2
    root.geometry(f"+{x}+{y}")

    ttk.Label(root, text="Descargando navegador", font=("", 13, "bold")).pack(pady=(18, 3))
    ttk.Label(
        root,
        text="Solo es necesario la primera vez  (~80 MB)",
        foreground="#666666",
    ).pack()

    pbar = ttk.Progressbar(root, length=380, mode="indeterminate")
    pbar.pack(padx=30, pady=(12, 5))
    pbar.start(12)

    status_var = tk.StringVar(value="Iniciando descarga…")
    ttk.Label(root, textvariable=status_var, foreground="#888888", font=("", 9)).pack()

    returncode_holder = [0]

    def _poll() -> None:
        try:
            while True:
                event, value, text = progress_q.get_nowait()

                if event == "progress":
                    pct = value  # int
                    if pbar.cget("mode") != "determinate":
                        pbar.stop()
                        pbar.configure(mode="determinate", maximum=100)
                    pbar.configure(value=pct)
                    if text:
                        status_var.set(_truncate(text, 58))

                elif event == "text":
                    if text:
                        status_var.set(_truncate(text, 58))

                elif event == "done":
                    returncode_holder[0] = int(value)  # type: ignore[arg-type]
                    if pbar.cget("mode") == "determinate":
                        pbar.configure(value=100)
                        root.after(300, root.destroy)
                    else:
                        root.destroy()
                    return

        except queue.Empty:
            pass

        root.after(80, _poll)

    root.after(80, _poll)
    root.mainloop()

    return returncode_holder[0]


def _wait_headless(progress_q: "queue.Queue[tuple[str, object, str]]") -> int:
    """Block until the install thread finishes. Used when no display is available."""
    while True:
        event, value, _ = progress_q.get()
        if event == "done":
            return int(value)  # type: ignore[arg-type]


def _truncate(text: str, max_len: int) -> str:
    return text if len(text) <= max_len else text[:max_len - 1] + "…"
