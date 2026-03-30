from pathlib import Path

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

    @property
    def cookies_file(self) -> Path:
        return self.data_dir / "cookies.json"

    @property
    def state_file(self) -> Path:
        return self.data_dir / "sync_state.json"

    @property
    def downloads_dir(self) -> Path:
        return self.data_dir / "downloads"
