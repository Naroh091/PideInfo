from __future__ import annotations

from pathlib import Path

from rich.console import Console

console = Console()


class DownloadManager:
    """Manages temporary downloaded files."""

    def __init__(self, downloads_dir: Path):
        self.downloads_dir = downloads_dir
        self.downloads_dir.mkdir(parents=True, exist_ok=True)

    def get_download_path(self, notification_id: int, document_id: int, extension: str = ".pdf") -> Path:
        """Get the path where a document should be downloaded."""
        filename = f"notif_{notification_id}_doc_{document_id}{extension}"
        return self.downloads_dir / filename

    def cleanup_file(self, path: Path) -> None:
        """Delete a downloaded file after successful sync."""
        if path.exists():
            path.unlink()

    def cleanup_stale(self, max_age_hours: int = 24) -> int:
        """Remove downloads older than max_age_hours. Returns count of removed files."""
        import time

        removed = 0
        cutoff = time.time() - max_age_hours * 3600

        for f in self.downloads_dir.iterdir():
            if f.is_file() and f.stat().st_mtime < cutoff:
                f.unlink()
                removed += 1

        if removed:
            console.print(f"[dim]Limpiados {removed} archivos temporales antiguos[/]")
        return removed
