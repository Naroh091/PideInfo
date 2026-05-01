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

from protocol.registration import register as _register_handler, is_registered as _is_registered
from storage.preferences import ACCEPT_NOTIFICATIONS_AVAILABLE
from version import __version__
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
        configure_fn: Callable[[], None],
        get_portal_enabled_fn: "Callable[[str], bool] | None" = None,
        toggle_portal_fn: "Callable[[str], None] | None" = None,
        debug_mode: bool = False,
        get_headless_disabled_fn: "Callable[[], bool] | None" = None,
        toggle_headless_disabled_fn: "Callable[[], None] | None" = None,
        sync_interval_minutes: int = 30,
        drain_tasks_fn: "Callable[[], None] | None" = None,
    ) -> None:
        self._sync_fn = sync_fn
        self._reset_fn = reset_fn
        self._get_accept_notifications = get_accept_notifications_fn
        self._toggle_accept_notifications = toggle_accept_notifications_fn
        self._connect_fn = connect_fn
        self._disconnect_fn = disconnect_fn
        self._is_connected = is_connected_fn
        self._get_user_email = get_user_email_fn
        self._configure_fn = configure_fn
        self._get_portal_enabled = get_portal_enabled_fn or (lambda _: True)
        self._toggle_portal = toggle_portal_fn or (lambda _: None)
        self._debug_mode = debug_mode
        self._get_headless_disabled = get_headless_disabled_fn or (lambda: False)
        self._toggle_headless_disabled = toggle_headless_disabled_fn or (lambda: None)
        self._sync_interval_minutes = sync_interval_minutes
        self._drain_tasks_fn = drain_tasks_fn
        self._draining_tasks = False
        self._loop: asyncio.AbstractEventLoop = asyncio.new_event_loop()
        self._icon: "pystray.Icon | None" = None
        self._syncing = False
        # Update state: (version_str, download_url) or None
        self._pending_update: "tuple[str, str] | None" = None

    # ------------------------------------------------------------------
    # Internal helpers
    # ------------------------------------------------------------------

    def _run_loop(self) -> None:
        asyncio.set_event_loop(self._loop)
        self._loop.run_forever()

    def _submit(self, coro: Coroutine[Any, Any, None]) -> None:
        asyncio.run_coroutine_threadsafe(coro, self._loop)

    def _set_syncing(self, value: bool, label: str = "sincronizando…") -> None:
        self._syncing = value
        if self._icon:
            self._icon.icon = _make_icon(syncing=value)
            self._icon.title = f"PideInfo Agent — {label}" if value else "PideInfo Agent"

    def _rebuild_menu(self) -> None:
        """Rebuild and update the tray menu (e.g. after connect/disconnect)."""
        if self._icon:
            self._icon.menu = self._build_menu()
            self._icon.update_menu()

    # ------------------------------------------------------------------
    # Menu callbacks (called from the tray thread)
    # ------------------------------------------------------------------

    async def _scheduled_sync(self) -> None:
        """Periodic sync — skipped if already syncing."""
        if self._syncing:
            return
        self._set_syncing(True)
        try:
            await self._sync_fn()
        except Exception as exc:
            console.print(f"[red]Error en sincronización: {exc}[/]")
        finally:
            self._set_syncing(False)

    def _on_sync(self, icon: "pystray.Icon", item: "pystray.MenuItem") -> None:
        if self._syncing:
            return
        self._submit(self._scheduled_sync())

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

    def _make_portal_toggle(self, portal_id: str) -> "Callable":
        def _handler(icon: "pystray.Icon", item: "pystray.MenuItem") -> None:
            self._toggle_portal(portal_id)
            self._rebuild_menu()
        return _handler

    def _on_connect(self, icon: "pystray.Icon", item: "pystray.MenuItem") -> None:
        self._connect_fn()
        self._rebuild_menu()

    def _on_disconnect(self, icon: "pystray.Icon", item: "pystray.MenuItem") -> None:
        self._disconnect_fn()
        self._rebuild_menu()

    def _on_toggle_headless_disabled(self, icon: "pystray.Icon", item: "pystray.MenuItem") -> None:
        self._toggle_headless_disabled()
        self._rebuild_menu()

    def _on_check_pending_tasks(self, icon: "pystray.Icon", item: "pystray.MenuItem") -> None:
        if self._drain_tasks_fn is None or self._draining_tasks:
            return

        async def _run() -> None:
            self._draining_tasks = True
            self._set_syncing(True, label="comprobando tareas…")
            self._rebuild_menu()
            try:
                # drain_tasks_fn is a sync function (httpx.Client). Run it off the
                # event loop to avoid blocking the asyncio thread.
                await asyncio.to_thread(self._drain_tasks_fn)
            except Exception as exc:
                console.print(f"[red]Comprobar tareas pendientes: {exc}[/]")
            finally:
                self._draining_tasks = False
                self._set_syncing(False)
                self._rebuild_menu()

        self._submit(_run())

    def _on_configure(self, icon: "pystray.Icon", item: "pystray.MenuItem") -> None:
        self._configure_fn()

    def _on_register_handler(self, _icon: "pystray.Icon", _item: "pystray.MenuItem") -> None:
        ok, msg = _register_handler()
        try:
            from notifier.desktop import _notify
            _notify("PideInfo Agent", msg)
        except Exception:
            console.print(msg)
        if ok:
            self._rebuild_menu()

    def _on_quit(self, icon: "pystray.Icon", item: "pystray.MenuItem") -> None:
        self._loop.call_soon_threadsafe(self._loop.stop)
        icon.stop()

    def _on_update(self, icon: "pystray.Icon", item: "pystray.MenuItem") -> None:
        if not self._pending_update:
            return
        version_str, download_url = self._pending_update

        async def _run() -> None:
            from updater.github_updater import download_update, apply_update
            console.print(f"[bold]Descargando actualización v{version_str}...[/]")
            try:
                path = await download_update(download_url)
                apply_update(path)
            except Exception as e:
                console.print(f"[red]Error descargando actualización: {e}[/]")

        self._submit(_run())

    async def _check_for_updates(self) -> None:
        """Background task: check GitHub for a newer agent version."""
        from updater.github_updater import check_for_update
        result = await check_for_update()
        if result:
            version_str, download_url = result
            self._pending_update = (version_str, download_url)
            self._rebuild_menu()
            console.print(f"[bold yellow]Nueva versión disponible: v{version_str}[/]")
            try:
                from notifier.desktop import _notify
                _notify(
                    "PideInfo Agent — Actualización disponible",
                    f"Nueva versión v{version_str} disponible. Haz clic en el menú para actualizar.",
                )
            except Exception:
                pass

    # ------------------------------------------------------------------
    # Public API
    # ------------------------------------------------------------------

    def _build_menu(self) -> "pystray.Menu":
        """Build (or rebuild) the tray menu with current state."""
        if not self._is_connected():
            # Disconnected: show Connect + Configurar + Cerrar
            return pystray.Menu(
                pystray.MenuItem("Conectar", self._on_connect),
                pystray.MenuItem("Configurar...", self._on_configure),
                pystray.MenuItem(
                    lambda item: "Handler pideinfo:// registrado" if _is_registered() else "Registrar handler de pideinfo://",
                    self._on_register_handler,
                    enabled=lambda item: not _is_registered(),
                ),
                pystray.Menu.SEPARATOR,
                pystray.MenuItem(f"PideInfo Agent v{__version__}", None, enabled=False),
                pystray.MenuItem("Cerrar", self._on_quit),
            )

        # Connected: full menu
        items = [
            pystray.MenuItem("Sincronizar ahora", self._on_sync),
        ]
        if self._drain_tasks_fn is not None:
            items.append(
                pystray.MenuItem(
                    lambda item: "Comprobando tareas pendientes…" if self._draining_tasks else "Comprobar tareas pendientes",
                    self._on_check_pending_tasks,
                    enabled=lambda item: not self._draining_tasks,
                )
            )
        items += [
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

        # Portal sync toggles
        _portals = [
            ("transparencia", "Portal de Transparencia"),
            ("ctbg", "Consejo de Transparencia (CTBG)"),
            ("dehu", "DEHú"),
            ("redsara", "Red SARA REC"),
        ]
        items.append(pystray.Menu.SEPARATOR)
        for portal_id, label in _portals:
            _pid = portal_id  # capture for closure
            items.append(
                pystray.MenuItem(
                    label,
                    self._make_portal_toggle(_pid),
                    checked=lambda item, pid=_pid: self._get_portal_enabled(pid),
                )
            )

        email = self._get_user_email()
        items += [
            pystray.Menu.SEPARATOR,
            pystray.MenuItem("Configurar...", self._on_configure),
            pystray.MenuItem(
                lambda item: "Handler pideinfo:// registrado" if _is_registered() else "Registrar handler de pideinfo://",
                self._on_register_handler,
                enabled=lambda item: not _is_registered(),
            ),
            pystray.MenuItem("Desconectar", self._on_disconnect),
            pystray.MenuItem(
                f"Conectado como {email}" if email else "Conectado",
                None,
                enabled=False,
            ),
            pystray.Menu.SEPARATOR,
        ]

        if self._pending_update:
            ver, _ = self._pending_update
            items.append(
                pystray.MenuItem(f"Actualizar a v{ver}...", self._on_update)
            )

        if self._debug_mode:
            items += [
                pystray.Menu.SEPARATOR,
                pystray.MenuItem(
                    "Deshabilitar headless",
                    self._on_toggle_headless_disabled,
                    checked=lambda item: self._get_headless_disabled(),
                ),
            ]

        items += [
            pystray.MenuItem(f"PideInfo Agent v{__version__}", None, enabled=False),
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

        # Schedule periodic sync + update check on the asyncio loop thread —
        # AsyncIOScheduler requires its constructor and start() to run there.
        async def _start_schedulers() -> None:
            try:
                from apscheduler.schedulers.asyncio import AsyncIOScheduler
                scheduler = AsyncIOScheduler()
                scheduler.add_job(
                    self._scheduled_sync,
                    "interval",
                    minutes=self._sync_interval_minutes,
                    id="periodic-sync",
                    max_instances=1,
                )
                scheduler.add_job(
                    self._check_for_updates,
                    "interval",
                    hours=6,
                    id="update-check",
                    max_instances=1,
                )
                if self._drain_tasks_fn is not None:
                    async def _drain_async() -> None:
                        self._draining_tasks = True
                        self._set_syncing(True, label="comprobando tareas…")
                        try:
                            await asyncio.to_thread(self._drain_tasks_fn)
                        except Exception as exc:
                            console.print(f"[yellow]drain_tasks_job: {exc}[/]")
                        finally:
                            self._draining_tasks = False
                            self._set_syncing(False)
                    scheduler.add_job(
                        _drain_async,
                        "interval",
                        seconds=60,
                        id="drain_agent_tasks",
                        max_instances=1,
                    )
                scheduler.start()
                asyncio.create_task(self._scheduled_sync())
                asyncio.create_task(self._check_for_updates())
            except Exception as e:
                console.print(f"[dim]No se pudo iniciar los schedulers: {e}[/]")

        self._submit(_start_schedulers())
        console.print(
            f"[dim]Sincronización automática cada {self._sync_interval_minutes} minutos[/]"
        )

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
