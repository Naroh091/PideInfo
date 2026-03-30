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

        # Initialize PideInfo client
        pideinfo = PideInfoClient(
            webhook_url=settings.pideinfo_webhook_url,
            webhook_secret=settings.pideinfo_webhook_secret,
            user_id=settings.pideinfo_user_id,
        )

        synced_count = 0

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

        # --- Sync expediente documents ---
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
