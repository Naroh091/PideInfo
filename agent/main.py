#!/usr/bin/env python3
"""PideInfo Agent — Syncs Portal de Transparencia with PideInfo."""

from __future__ import annotations

import argparse
import asyncio
import sys

from rich.console import Console
from rich.table import Table

from auth.session_manager import SessionManager, SessionExpiredError
from client.pideinfo import PideInfoClient
from config import Settings
from models.portal import Notificacion
from notifier.desktop import (
    notify_auth_required,
    notify_error,
    notify_new_documents,
    notify_pending_signatures,
)
from portals.transparencia_age import TransparenciaAGEScraper
from storage.downloads import DownloadManager
from storage.preferences import ACCEPT_NOTIFICATIONS_AVAILABLE, AgentPreferences, load_preferences, save_preferences
from storage.state import SyncState, load_state, save_state

console = Console()


def _make_pideinfo_client(settings: Settings, prefs: AgentPreferences) -> PideInfoClient:
    """Create a PideInfoClient using JWT if connected, legacy auth otherwise."""
    if prefs.is_connected:
        return PideInfoClient(
            base_url=settings.pideinfo_base_url,
            jwt_token=prefs.jwt_token,
        )
    return PideInfoClient(
        base_url=settings.pideinfo_base_url,
        webhook_url=settings.pideinfo_webhook_url,
        webhook_secret=settings.pideinfo_webhook_secret,
        user_id=settings.pideinfo_user_id,
    )


async def do_auth(settings: Settings) -> dict[str, str]:
    """Authenticate and return cookies."""
    session = SessionManager(
        portal_url=settings.portal_url,
        cookies_file=settings.cookies_file,
        auth_timeout=settings.auth_timeout_seconds,
        client_cert_p12=settings.client_cert_p12,
        client_cert_passphrase=settings.client_cert_passphrase,
    )
    notify_auth_required()
    cookies = await session.get_valid_session()
    console.print(f"[green]Autenticación OK — {len(cookies)} cookies obtenidas[/]")
    return cookies


async def do_sync(settings: Settings, dry_run: bool = False, prefs: "AgentPreferences | None" = None) -> None:
    """Run a single sync cycle."""
    # Initialize components
    session = SessionManager(
        portal_url=settings.portal_url,
        cookies_file=settings.cookies_file,
        auth_timeout=settings.auth_timeout_seconds,
        client_cert_p12=settings.client_cert_p12,
        client_cert_passphrase=settings.client_cert_passphrase,
    )
    downloads = DownloadManager(settings.downloads_dir)
    state = load_state(settings.state_file)

    # Clean up stale downloads
    downloads.cleanup_stale()

    # Get valid session
    try:
        await session.get_valid_session()
    except Exception as e:
        notify_error(f"Error de autenticación: {e}")
        raise

    scraper = TransparenciaAGEScraper(session)

    try:
        # Fetch data from portal
        console.print("\n[bold]Obteniendo expedientes...[/]")
        expedientes = await scraper.get_expedientes()

        console.print("[bold]Obteniendo notificaciones...[/]")
        notificaciones = await scraper.get_notificaciones()

        # Show summary
        _print_summary(expedientes, notificaciones, state)

        # Filter: only downloadable notifications not yet synced.
        # When accept_notifications is enabled, also include PENDIENTE ones.
        accept_notifications = ACCEPT_NOTIFICATIONS_AVAILABLE and (prefs.accept_notifications if prefs else False)

        def _should_sync(n: Notificacion) -> bool:
            if state.is_document_synced(n.id_expediente, n.id_documento):
                return False
            if n.is_downloadable:
                return True
            if accept_notifications and n.estado == "PENDIENTE":
                return True
            return False

        to_sync = [n for n in notificaciones if _should_sync(n)]

        # Count pending signatures
        pending = [n for n in notificaciones if n.estado == "PENDIENTE"]
        if pending:
            console.print(
                f"\n[yellow]⚠ {len(pending)} notificación(es) pendiente(s) de firma "
                f"(requieren intervención manual en el portal)[/]"
            )
            notify_pending_signatures(len(pending))

        # Initialize PideInfo client
        if prefs is None:
            prefs = AgentPreferences()
        pideinfo = _make_pideinfo_client(settings, prefs)

        synced_count = 0

        # --- Sync expediente documents FIRST ---
        console.print("\n[bold]Sincronizando documentos de expedientes...[/]")
        exp_synced = 0
        for exp in expedientes:
            if exp.es_ac1 or exp.es_acceda1_pee or exp.is_borrador:
                continue
            try:
                exp_synced += await _sync_expediente_docs(
                    exp, scraper, pideinfo, downloads, state, dry_run
                )
            except SessionExpiredError:
                console.print("[yellow]Sesión expirada durante sincronización de expedientes[/]")
                notify_error("Sesión expirada. Ejecuta de nuevo para re-autenticar.")
                break
            except Exception as e:
                console.print(f"[red]Error sincronizando expediente {exp.identificador}: {e}[/]")

        synced_count += exp_synced

        # --- Sync notification documents ---
        if not to_sync:
            console.print("\n[green]No hay notificaciones nuevas para sincronizar[/]")
        else:
            console.print(f"\n[bold]{len(to_sync)} notificación(es) nueva(s) para sincronizar[/]")

        if dry_run and to_sync:
            console.print("[yellow]Modo dry-run: no se descargan ni sincronizan notificaciones[/]")
            for n in to_sync:
                console.print(f"  [dim]• {n.tipo} — {n.identificador} ({n.estado})[/]")
        else:
            for notif in to_sync:
                try:
                    await _sync_notification(notif, scraper, pideinfo, downloads, state)
                    synced_count += 1
                except SessionExpiredError:
                    console.print("[yellow]Sesión expirada durante sincronización[/]")
                    notify_error("Sesión expirada. Ejecuta de nuevo para re-autenticar.")
                    break
                except Exception as e:
                    console.print(f"[red]Error sincronizando notificación {notif.id}: {e}[/]")

        # Report PENDIENTE notifications to PideInfo when auto-accept is off,
        # but only those whose document hasn't already been synced via the expediente path.
        # Expedientes that had pending notifications but are now all synced get an explicit clear.
        if not accept_notifications and not dry_run:
            await _report_pending_notifications(notificaciones, pideinfo, state, expedientes)

        # Save state
        state.mark_sync_complete()
        save_state(state, settings.state_file)

        if synced_count > 0:
            notify_new_documents(synced_count)
        console.print(f"\n[green]Sincronización completada: {synced_count} documento(s)[/]")

    finally:
        await scraper.close()


async def _sync_notification(
    notif: Notificacion,
    scraper: TransparenciaAGEScraper,
    pideinfo: PideInfoClient,
    downloads: DownloadManager,
    state: SyncState,
) -> None:
    """Download and sync a single notification's document."""
    dest = downloads.get_download_path(notif.id, notif.id_documento)

    # Download
    console.print(f"[dim]Descargando {notif.tipo} — {notif.identificador}...[/]")
    await scraper.download_document(notif.download_url, dest)

    # Sync to PideInfo
    result = await pideinfo.sync_notification(notif, dest)

    # Update state
    state.mark_document_synced(notif.id_expediente, notif.id_documento)
    state.mark_notification_synced(notif.id)

    # Cleanup downloaded file
    downloads.cleanup_file(dest)


async def _report_pending_notifications(
    notificaciones: "list[Notificacion]",
    pideinfo: PideInfoClient,
    state: SyncState,
    expedientes: "list",
) -> None:
    """Group PENDIENTE notifications by expediente and report each group to PideInfo.

    Notifications whose document was already synced (via the expediente path) are
    excluded — they remain PENDIENTE on the portal but the content is already imported.
    Expedientes that previously had pending notifications but now have all their
    documents synced receive an explicit empty report to clear the banner in PideInfo.
    """
    from collections import defaultdict

    # Build a map of id_expediente → identificador for the clear pass
    exp_ref_by_id = {exp.id: exp.identificador for exp in expedientes}

    # Separate: which expedientes have at least one truly-pending (unsynced) notification?
    all_pending: dict[int, list[Notificacion]] = defaultdict(list)
    already_synced_exp_ids: set[int] = set()

    for n in notificaciones:
        if n.estado != "PENDIENTE":
            continue
        if state.is_document_synced(n.id_expediente, n.id_documento):
            already_synced_exp_ids.add(n.id_expediente)
        else:
            all_pending[n.id_expediente].append(n)

    # Expedientes whose notifications are all already synced → send a clear (empty list)
    to_clear = already_synced_exp_ids - set(all_pending.keys())

    for id_expediente in to_clear:
        expediente_ref = exp_ref_by_id.get(id_expediente)
        if not expediente_ref:
            continue
        try:
            await pideinfo.report_pending_notifications(id_expediente, expediente_ref, [])
        except Exception as e:
            console.print(f"[red]Error limpiando pendientes de {expediente_ref}: {e}[/]")

    if not all_pending:
        return

    console.print(
        f"\n[dim]Reportando notificaciones pendientes de {len(all_pending)} expediente(s)...[/]"
    )
    for id_expediente, pending in all_pending.items():
        expediente_ref = pending[0].identificador
        try:
            await pideinfo.report_pending_notifications(id_expediente, expediente_ref, pending)
        except Exception as e:
            console.print(
                f"[red]Error reportando pendientes de {expediente_ref}: {e}[/]"
            )


async def _sync_expediente_docs(
    expediente,
    scraper: TransparenciaAGEScraper,
    pideinfo: PideInfoClient,
    downloads: DownloadManager,
    state: SyncState,
    dry_run: bool,
) -> int:
    """Download and sync new documents from an expediente's documentosData. Returns count synced."""
    portal_id, documentos = await scraper.get_expediente_detail(expediente.id)

    to_sync = [
        doc for doc in documentos
        if not state.is_document_synced(expediente.id, doc.id)
        and not doc.nombre.startswith("JUSTIFICANTE_COMPARECENCIA")
    ]

    if not to_sync:
        return 0

    console.print(
        f"[dim]{expediente.identificador}: {len(to_sync)} doc(s) nuevo(s)[/]"
    )

    if dry_run:
        for doc in to_sync:
            console.print(f"  [dim]• {doc.nombre}[/]")
        return 0

    # Download all documents first, then send as a single batch so the
    # batch handler can analyze them together (SOLICITUD first for correct
    # AccessRequest creation).
    downloaded: list[tuple] = []  # (DocumentoExpediente, Path)
    for doc in to_sync:
        dest = downloads.get_download_path(expediente.id, doc.id)
        try:
            console.print(f"[dim]Descargando {doc.nombre}...[/]")
            await scraper.download_document(doc.download_url, dest)
            downloaded.append((doc, dest))
        except Exception as e:
            console.print(f"[red]Error descargando {doc.nombre}: {e}[/]")

    if not downloaded:
        return 0

    synced = 0
    try:
        await pideinfo.sync_expediente_documents(expediente, downloaded, portal_id)
        for doc, _ in downloaded:
            state.mark_document_synced(expediente.id, doc.id)
        synced = len(downloaded)
    except Exception as e:
        console.print(f"[red]Error sincronizando expediente {expediente.identificador}: {e}[/]")
    finally:
        for _, dest in downloaded:
            downloads.cleanup_file(dest)

    return synced


def _print_summary(expedientes, notificaciones, state: SyncState) -> None:
    """Print a summary table of portal data."""
    table = Table(title="Resumen del Portal de Transparencia")
    table.add_column("Concepto", style="bold")
    table.add_column("Total", justify="right")
    table.add_column("Ya sincronizados", justify="right")

    downloadable = [n for n in notificaciones if n.is_downloadable]
    already_synced = [
        n for n in downloadable
        if state.is_document_synced(n.id_expediente, n.id_documento)
    ]

    table.add_row("Expedientes", str(len(expedientes)), "—")
    table.add_row("Notificaciones", str(len(notificaciones)), "—")
    table.add_row("Documentos descargables", str(len(downloadable)), str(len(already_synced)))
    table.add_row(
        "Pendientes de firma",
        str(len([n for n in notificaciones if n.estado == "PENDIENTE"])),
        "—",
    )

    console.print(table)


async def do_daemon(settings: Settings) -> None:
    """Run sync on a schedule."""
    from apscheduler.schedulers.asyncio import AsyncIOScheduler

    prefs = load_preferences(settings.preferences_file)
    console.print(
        f"[bold]Modo daemon — sincronizando cada {settings.sync_interval_minutes} minutos[/]"
    )

    scheduler = AsyncIOScheduler()
    scheduler.add_job(
        do_sync,
        "interval",
        minutes=settings.sync_interval_minutes,
        args=[settings],
        kwargs={"prefs": prefs},
        id="sync",
        name="Portal sync",
        max_instances=1,
    )
    scheduler.start()

    # Run immediately on start
    await do_sync(settings, prefs=prefs)

    # Keep running
    try:
        while True:
            await asyncio.sleep(1)
    except (KeyboardInterrupt, SystemExit):
        console.print("\n[yellow]Deteniendo agente...[/]")
        scheduler.shutdown()


def _run_tray(settings: Settings) -> None:
    """Launch the system tray icon with connection-aware menu."""
    from tray import TrayApp

    prefs = load_preferences(settings.preferences_file)

    async def sync() -> None:
        try:
            await do_sync(settings, prefs=prefs)
        except Exception as e:
            notify_error(str(e))

    async def reset() -> None:
        settings.state_file.unlink(missing_ok=True)
        settings.cookies_file.unlink(missing_ok=True)
        console.print("[yellow]Estado y sesión reiniciados[/]")

    def get_accept_notifications() -> bool:
        return prefs.accept_notifications

    def toggle_accept_notifications() -> None:
        prefs.accept_notifications = not prefs.accept_notifications
        save_preferences(prefs, settings.preferences_file)
        state = "activada" if prefs.accept_notifications else "desactivada"
        console.print(f"[yellow]Aceptación automática de notificaciones {state}[/]")

    def connect() -> None:
        from ui.connect_dialog import show_connect_dialog, show_connected_card, show_error_dialog

        token = show_connect_dialog()
        if not token:
            return

        # Validate token against the backend
        import asyncio as _asyncio
        client = PideInfoClient(base_url=settings.pideinfo_base_url, jwt_token=token)
        try:
            loop = _asyncio.new_event_loop()
            user_info = loop.run_until_complete(client.validate_token())
            loop.close()
        except Exception as e:
            console.print(f"[red]Error validando token: {e}[/]")
            show_error_dialog(f"No se pudo validar el token.\n{e}")
            return

        # Store in preferences
        prefs.jwt_token = token
        prefs.user_email = user_info.get("email", "")
        prefs.user_name = user_info.get("name", "")
        save_preferences(prefs, settings.preferences_file)

        console.print(f"[green]Conectado como {prefs.user_email}[/]")
        show_connected_card(prefs.user_name, prefs.user_email)

    def disconnect() -> None:
        prefs.jwt_token = ""
        prefs.user_email = ""
        prefs.user_name = ""
        save_preferences(prefs, settings.preferences_file)
        console.print("[yellow]Desconectado de PideInfo[/]")

    def is_connected() -> bool:
        return prefs.is_connected

    def get_user_email() -> str:
        return prefs.user_email

    TrayApp(
        sync_fn=sync,
        reset_fn=reset,
        get_accept_notifications_fn=get_accept_notifications,
        toggle_accept_notifications_fn=toggle_accept_notifications,
        connect_fn=connect,
        disconnect_fn=disconnect,
        is_connected_fn=is_connected,
        get_user_email_fn=get_user_email,
    ).run()


def main() -> None:
    parser = argparse.ArgumentParser(
        description="PideInfo Agent — Sincronización con Portal de Transparencia",
    )
    parser.add_argument(
        "--once",
        action="store_true",
        help="Ejecutar un solo ciclo de sincronización",
    )
    parser.add_argument(
        "--daemon",
        action="store_true",
        help="Ejecutar en modo daemon con sincronización periódica",
    )
    parser.add_argument(
        "--auth-only",
        action="store_true",
        help="Solo autenticar (para testing)",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Scrapear pero no sincronizar con PideInfo",
    )
    parser.add_argument(
        "--tray",
        action="store_true",
        help="Ejecutar como icono en la barra del sistema",
    )
    parser.add_argument(
        "--env-file",
        default=".env",
        help="Ruta al fichero .env (default: .env)",
    )

    args = parser.parse_args()

    # Load settings
    settings = Settings(_env_file=args.env_file)
    settings.data_dir.mkdir(parents=True, exist_ok=True)

    console.print("[bold]PideInfo Agent[/]")
    console.print(f"[dim]Portal: {settings.portal_url}[/]")
    console.print(f"[dim]PideInfo: {settings.pideinfo_base_url}[/]")
    console.print(f"[dim]Datos: {settings.data_dir}[/]")

    if args.auth_only:
        asyncio.run(do_auth(settings))
    elif args.tray:
        _run_tray(settings)
    elif args.daemon:
        asyncio.run(do_daemon(settings))
    elif args.once or args.dry_run:
        asyncio.run(do_sync(settings, dry_run=args.dry_run))
    else:
        # Default: single sync
        asyncio.run(do_sync(settings))


if __name__ == "__main__":
    main()
