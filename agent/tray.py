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

from storage.preferences import ACCEPT_NOTIFICATIONS_AVAILABLE
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

        app = TrayApp(sync_fn=..., reset_fn=..., get_accept_notifications_fn=...,
                       toggle_accept_notifications_fn=..., connect_fn=...,
                       disconnect_fn=..., is_connected_fn=..., get_user_email_fn=...)
        app.run()   # blocks — call from the main thread
    """

    def __init__(
        self,
        sync_fn: Callable[[], Coroutine[Any, Any, None]],
        reset_fn: Callable[[], Coroutine[Any, Any, None]],
        get_accept_notifications_fn: Callable[[], bool],
        toggle_accept_notifications_fn: Callable[[], None],
        connect_fn: Callable[[], None],
        disconnect_fn: Callable[[], None],
        is_connected_fn: Callable[[], bool],
        get_user_email_fn: Callable[[], str],
    ) -> None:
        self._sync_fn = sync_fn
        self._reset_fn = reset_fn
        self._get_accept_notifications = get_accept_notifications_fn
        self._toggle_accept_notifications = toggle_accept_notifications_fn
        self._connect_fn = connect_fn
        self._disconnect_fn = disconnect_fn
        self._is_connected = is_connected_fn
        self._get_user_email = get_user_email_fn
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

    def _rebuild_menu(self) -> None:
        """Rebuild and update the tray menu (e.g. after connect/disconnect)."""
        if self._icon:
            self._icon.menu = self._build_menu()
            self._icon.update_menu()

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
        self._rebuild_menu()

    def _on_connect(self, icon: "pystray.Icon", item: "pystray.MenuItem") -> None:
        self._connect_fn()
        self._rebuild_menu()

    def _on_disconnect(self, icon: "pystray.Icon", item: "pystray.MenuItem") -> None:
        self._disconnect_fn()
        self._rebuild_menu()

    def _on_quit(self, icon: "pystray.Icon", item: "pystray.MenuItem") -> None:
        self._loop.call_soon_threadsafe(self._loop.stop)
        icon.stop()

    # ------------------------------------------------------------------
    # Public API
    # ------------------------------------------------------------------

    def _build_menu(self) -> "pystray.Menu":
        """Build (or rebuild) the tray menu with current state."""
        if not self._is_connected():
            # Disconnected: only show Connect + Cerrar
            return pystray.Menu(
                pystray.MenuItem("Conectar", self._on_connect),
                pystray.Menu.SEPARATOR,
                pystray.MenuItem("Cerrar", self._on_quit),
            )

        # Connected: full menu
        items = [
            pystray.MenuItem("Sincronizar ahora", self._on_sync),
            pystray.MenuItem("Resetear", self._on_reset),
            pystray.Menu.SEPARATOR,
        ]

        if ACCEPT_NOTIFICATIONS_AVAILABLE:
            items.append(
                pystray.MenuItem(
                    "Aceptar notificaciones electrónicas",
                    self._on_toggle_accept_notifications,
                    checked=lambda item: self._get_accept_notifications(),
                )
            )
        else:
            items.append(
                pystray.MenuItem(
                    "Aceptar notificaciones electrónicas (no disponible)",
                    None,
                    enabled=False,
                )
            )

        email = self._get_user_email()
        items += [
            pystray.Menu.SEPARATOR,
            pystray.MenuItem("Desconectar", self._on_disconnect),
            pystray.MenuItem(
                f"Conectado como {email}" if email else "Conectado",
                None,
                enabled=False,
            ),
            pystray.Menu.SEPARATOR,
            pystray.MenuItem("Cerrar", self._on_quit),
        ]
        return pystray.Menu(*items)

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
