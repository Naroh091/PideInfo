from pathlib import Path

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
    )

    # Portal
    portal_url: str = "https://transparencia.sede.gob.es"
    portal_ctbg: str = "https://sede.consejodetransparencia.gob.es/info.0"
    portal_dehu: str = "https://dehu.redsara.es"

    # PideInfo
    pideinfo_base_url: str = "http://localhost:8000"

    # Agent
    auth_timeout_seconds: int = 120
    sync_interval_minutes: int = 30
    data_dir: Path = Path.home() / ".pideinfo-agent"

    @property
    def portal_ctbg_base(self) -> str:
        """Base URL for CTBG sede (strip page path like /info.0)."""
        from urllib.parse import urlparse
        parsed = urlparse(self.portal_ctbg)
        return f"{parsed.scheme}://{parsed.netloc}"

    @property
    def cookies_file(self) -> Path:
        return self.data_dir / "cookies.json"

    @property
    def preferences_file(self) -> Path:
        return self.data_dir / "preferences.json"

    @property
    def cookies_ctbg_file(self) -> Path:
        return self.data_dir / "cookies_ctbg.json"

    @property
    def cookies_dehu_file(self) -> Path:
        return self.data_dir / "cookies_dehu.json"

    @property
    def state_file(self) -> Path:
        return self.data_dir / "sync_state.json"

    @property
    def downloads_dir(self) -> Path:
        return self.data_dir / "downloads"

    @property
    def playwright_browsers_dir(self) -> Path:
        """Platform-appropriate directory for Playwright browser binaries."""
        from runtime import get_playwright_browsers_dir
        return get_playwright_browsers_dir()

    @property
    def firefox_profile_dir(self) -> Path:
        """Persistent Firefox profile that remembers certificate selections."""
        return self.data_dir / "firefox-profile"
