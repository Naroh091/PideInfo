"""
PideInfo Agent — System tray / menu bar integration.

Uses pystray (multiplatform) + Pillow for the icon.
- macOS : menu bar icon (NSStatusBar)
- Windows: system tray icon
- Linux  : AppIndicator / libayatana-appindicator

The asyncio event loop runs in a background thread because macOS requires
the tray to own the main thread.
"""
from __future__ import annotations

import asyncio
import threading
from typing import Callable, Coroutine, Any

from rich.console import Console

console = Console()

# Lazy-import so the rest of the agent works even without pystray/Pillow
try:
    import pystray
    from PIL import Image, ImageDraw
    _AVAILABLE = True
except ImportError:
    _AVAILABLE = False



def _make_icon(size: int = 64, syncing: bool = False) -> "Image.Image":
    """Draw a simple circular icon. Blue at rest, amber while syncing."""
    img = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    draw = ImageDraw.Draw(img)

    fill = "#F59E0B" if syncing else "#1D4ED8"   # amber / blue
    m = 4
    draw.ellipse([m, m, size - m, size - m], fill=fill)

    # Stylised "P"
    draw.rectangle([size // 4, size // 6, size // 4 + 7, size * 5 // 6], fill="white")
    draw.ellipse([size // 4, size // 6, size * 3 // 4, size // 2], fill="white")
    draw.ellipse([size // 4 + 4, size // 6 + 4, size * 3 // 4 - 4, size // 2 - 4], fill=fill)

    return img


class TrayApp:
    """
    Wraps pystray and an asyncio event loop.

    Usage::

        app = TrayApp(sync_fn=..., reset_fn=..., get_accept_notifications_fn=..., toggle_accept_notifications_fn=...)
        app.run()   # blocks — call from the main thread
    """

    def __init__(
        self,
        sync_fn: Callable[[], Coroutine[Any, Any, None]],
        reset_fn: Callable[[], Coroutine[Any, Any, None]],
        get_accept_notifications_fn: Callable[[], bool],
        toggle_accept_notifications_fn: Callable[[], None],
    ) -> None:
        self._sync_fn = sync_fn
        self._reset_fn = reset_fn
        self._get_accept_notifications = get_accept_notifications_fn
        self._toggle_accept_notifications = toggle_accept_notifications_fn
        self._loop: asyncio.AbstractEventLoop = asyncio.new_event_loop()
        self._icon: "pystray.Icon | None" = None
        self._syncing = False

    # ------------------------------------------------------------------
    # Internal helpers
    # ------------------------------------------------------------------

    def _run_loop(self) -> None:
        asyncio.set_event_loop(self._loop)
        self._loop.run_forever()

    def _submit(self, coro: Coroutine[Any, Any, None]) -> None:
        asyncio.run_coroutine_threadsafe(coro, self._loop)

    def _set_syncing(self, value: bool) -> None:
        self._syncing = value
        if self._icon:
            self._icon.icon = _make_icon(syncing=value)
            self._icon.title = "PideInfo Agent — sincronizando…" if value else "PideInfo Agent"

    # ------------------------------------------------------------------
    # Menu callbacks (called from the tray thread)
    # ------------------------------------------------------------------

    def _on_sync(self, icon: "pystray.Icon", item: "pystray.MenuItem") -> None:
        if self._syncing:
            return

        async def _run() -> None:
            self._set_syncing(True)
            try:
                await self._sync_fn()
            except Exception as exc:
                console.print(f"[red]Error en sincronización: {exc}[/]")
            finally:
                self._set_syncing(False)

        self._submit(_run())

    def _on_reset(self, icon: "pystray.Icon", item: "pystray.MenuItem") -> None:
        async def _run() -> None:
            try:
                await self._reset_fn()
            except Exception as exc:
                console.print(f"[red]Error al resetear: {exc}[/]")

        self._submit(_run())

    def _on_toggle_accept_notifications(self, icon: "pystray.Icon", item: "pystray.MenuItem") -> None:
        self._toggle_accept_notifications()
        # Rebuild the menu so the checkmark updates
        icon.menu = self._build_menu()
        icon.update_menu()

    def _on_quit(self, icon: "pystray.Icon", item: "pystray.MenuItem") -> None:
        self._loop.call_soon_threadsafe(self._loop.stop)
        icon.stop()

    # ------------------------------------------------------------------
    # Public API
    # ------------------------------------------------------------------

    def _build_menu(self) -> "pystray.Menu":
        """Build (or rebuild) the tray menu with current preference state."""
        accept_enabled = self._get_accept_notifications()
        return pystray.Menu(
            pystray.MenuItem("Sincronizar ahora", self._on_sync),
            pystray.MenuItem("Resetear", self._on_reset),
            pystray.Menu.SEPARATOR,
            pystray.MenuItem(
                "Aceptar notificaciones electrónicas",
                self._on_toggle_accept_notifications,
                checked=lambda item: self._get_accept_notifications(),
            ),
            pystray.Menu.SEPARATOR,
            pystray.MenuItem("Cerrar", self._on_quit),
        )

    def run(self) -> None:
        """Start the tray icon. Blocks until the user chooses Cerrar."""
        if not _AVAILABLE:
            console.print(
                "[red]Instala las dependencias del tray:[/] "
                "pip install pystray Pillow"
            )
            return

        # Event loop lives in a background daemon thread
        loop_thread = threading.Thread(target=self._run_loop, daemon=True, name="asyncio-loop")
        loop_thread.start()

        self._icon = pystray.Icon(
            name="PideInfo Agent",
            icon=_make_icon(),
            title="PideInfo Agent",
            menu=self._build_menu(),
        )

        # Hide from Dock / taskbar BEFORE starting the run loop.
        # On macOS: NSApplication.sharedApplication() is idempotent — calling it
        # here initialises the app object so we can set the policy before pystray's
        # run loop takes over (doing it inside setup= is too late and breaks the icon).
        import sys
        if sys.platform == "darwin":
            try:
                from AppKit import NSApplication, NSApplicationActivationPolicyAccessory
                NSApplication.sharedApplication().setActivationPolicy_(
                    NSApplicationActivationPolicyAccessory
                )
            except Exception:
                pass
        elif sys.platform == "win32":
            try:
                import ctypes
                hwnd = ctypes.windll.kernel32.GetConsoleWindow()
                if hwnd:
                    ctypes.windll.user32.ShowWindow(hwnd, 0)  # SW_HIDE
            except Exception:
                pass

        console.print("[bold]PideInfo Agent — bandeja del sistema activa[/]")
        self._icon.run()   # blocks; macOS requires this on the main thread
