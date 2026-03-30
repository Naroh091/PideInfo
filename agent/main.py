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
from storage.state import SyncState, load_state, save_state

console = Console()


async def do_auth(settings: Settings) -> dict[str, str]:
    """Authenticate and return cookies."""
    session = SessionManager(
        portal_url=settings.portal_url,
        cookies_file=settings.cookies_file,
        auth_timeout=settings.auth_timeout_seconds,
    )
    notify_auth_required()
    cookies = await session.get_valid_session()
    console.print(f"[green]Autenticación OK — {len(cookies)} cookies obtenidas[/]")
    return cookies


async def do_sync(settings: Settings, dry_run: bool = False) -> None:
    """Run a single sync cycle."""
    # Initialize components
    session = SessionManager(
        portal_url=settings.portal_url,
        cookies_file=settings.cookies_file,
        auth_timeout=settings.auth_timeout_seconds,
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

        # Filter: only downloadable notifications not yet synced
        to_sync = [
            n for n in notificaciones
            if n.is_downloadable
            and not state.is_document_synced(n.id_expediente, n.id_documento)
        ]

        # Count pending signatures
        pending = [n for n in notificaciones if n.estado == "PENDIENTE"]
        if pending:
            console.print(
                f"\n[yellow]⚠ {len(pending)} notificación(es) pendiente(s) de firma "
                f"(requieren intervención manual en el portal)[/]"
            )
            notify_pending_signatures(len(pending))

        if not to_sync:
            console.print("\n[green]No hay documentos nuevos para sincronizar[/]")
            state.mark_sync_complete()
            save_state(state, settings.state_file)
            return

        console.print(f"\n[bold]{len(to_sync)} documento(s) nuevo(s) para sincronizar[/]")

        if dry_run:
            console.print("[yellow]Modo dry-run: no se descargan ni sincronizan documentos[/]")
            for n in to_sync:
                console.print(f"  [dim]• {n.tipo} — {n.identificador} ({n.estado})[/]")
            return

        # Initialize PideInfo client
        pideinfo = PideInfoClient(
            webhook_url=settings.pideinfo_webhook_url,
            webhook_secret=settings.pideinfo_webhook_secret,
            user_id=settings.pideinfo_user_id,
        )

        # Download and sync each document
        synced_count = 0
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

    console.print(
        f"[bold]Modo daemon — sincronizando cada {settings.sync_interval_minutes} minutos[/]"
    )

    scheduler = AsyncIOScheduler()
    scheduler.add_job(
        do_sync,
        "interval",
        minutes=settings.sync_interval_minutes,
        args=[settings],
        id="sync",
        name="Portal sync",
        max_instances=1,
    )
    scheduler.start()

    # Run immediately on start
    await do_sync(settings)

    # Keep running
    try:
        while True:
            await asyncio.sleep(1)
    except (KeyboardInterrupt, SystemExit):
        console.print("\n[yellow]Deteniendo agente...[/]")
        scheduler.shutdown()


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
    console.print(f"[dim]PideInfo: {settings.pideinfo_webhook_url}[/]")
    console.print(f"[dim]Datos: {settings.data_dir}[/]")

    if args.auth_only:
        asyncio.run(do_auth(settings))
    elif args.daemon:
        asyncio.run(do_daemon(settings))
    elif args.once or args.dry_run:
        asyncio.run(do_sync(settings, dry_run=args.dry_run))
    else:
        # Default: single sync
        asyncio.run(do_sync(settings))


if __name__ == "__main__":
    main()
