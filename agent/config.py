from pathlib import Path
from typing import Optional

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
    )

    # Portal
    portal_url: str = "https://transparencia.sede.gob.es"

    # PideInfo webhook
    pideinfo_webhook_url: str = "http://localhost:8000/webhook/transparencia-sync"
    pideinfo_webhook_secret: str = "change-me-in-production"
    pideinfo_user_id: str = ""

    # Agent
    auth_timeout_seconds: int = 120
    sync_interval_minutes: int = 30
    data_dir: Path = Path.home() / ".pideinfo-agent"

    # Optional: path to FNMT client certificate (.p12) for automatic selection
    client_cert_p12: Optional[Path] = None
    client_cert_passphrase: str = ""

    @property
    def cookies_file(self) -> Path:
        return self.data_dir / "cookies.json"

    @property
    def preferences_file(self) -> Path:
        return self.data_dir / "preferences.json"

    @property
    def state_file(self) -> Path:
        return self.data_dir / "sync_state.json"

    @property
    def downloads_dir(self) -> Path:
        return self.data_dir / "downloads"
