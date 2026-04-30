from __future__ import annotations

import urllib.parse
from pathlib import Path
from typing import Any

from playwright.async_api import async_playwright
from rich.console import Console

console = Console()


class AuthTimeoutError(Exception):
    pass


class AuthCancelledError(Exception):
    pass


class AuthFailedError(Exception):
    pass


# ---------------------------------------------------------------------------
# Authentication
# ---------------------------------------------------------------------------

async def authenticate(
    portal_url: str,
    timeout_seconds: int = 120,
    target_path: str = "/privada/expedientes",
    firefox_profile_dir: Path | None = None,
    force_headed: bool = False,
) -> dict[str, str]:
    """
    Open a headed Firefox browser with a persistent profile, navigate to the
    portal's private area, wait for the user to complete Cl@ve authentication,
    and return the session cookies.

    Uses a persistent Firefox profile so that certificate selections made in
    Firefox's cert picker are remembered between sessions.  The profile also
    enables ``security.osclientcerts.autoload`` so all certificates installed
    in the OS keychain (macOS Keychain, Windows Certificate Store) are
    automatically available — without exporting or copying the private key.

    On first use the user picks their certificate from Firefox's own picker.
    On subsequent authentications Firefox selects it automatically from the
    saved profile preference, with no dialog.
    """
    # First launch needs a visible window so the user can pick their certificate
    # from Firefox's native cert picker.  Once the persistent profile has been
    # initialised (prefs.js exists) the choice is remembered and we can run
    # headless — no UI required.
    profile_ready = (
        firefox_profile_dir is not None
        and (firefox_profile_dir / "prefs.js").exists()
    )
    headless = profile_ready and not force_headed
    cert_ready = (
        firefox_profile_dir is not None
        and (firefox_profile_dir / ".pideinfo-cert-ready").exists()
    )

    if headless:
        console.print("[dim]Autenticando Cl@ve en segundo plano...[/]")
    elif profile_ready:
        console.print("[bold yellow]Abriendo navegador (modo headless desactivado)...[/]")
    else:
        console.print("[bold yellow]Abriendo navegador para autenticación Cl@ve...[/]")
        console.print("[dim]Primera vez: elige tu certificado. A partir de ahora será silencioso.[/]")
    console.print(f"[dim]Timeout: {timeout_seconds}s[/]")

    firefox_user_prefs: dict[str, Any] = {
        # Load client certificates directly from the OS keychain — private key
        # never leaves the secure store.
        "security.osclientcerts.autoload": True,
        # Suppress the "restore previous session?" prompt.
        "browser.sessionstore.resume_from_crash": False,
        "browser.startup.page": 0,
    }
    # Auto-pick the cert in two cases:
    #   - headless run (no UI to show a picker)
    #   - the user has already picked once in this profile (cert_ready marker
    #     is dropped after first successful auth) — saves them from re-picking
    #     per origin on every new portal.
    if headless or cert_ready:
        firefox_user_prefs["security.default_personal_cert"] = "Select Automatically"

    async with async_playwright() as p:
        launch_kwargs: dict[str, Any] = dict(
            headless=headless,
            firefox_user_prefs=firefox_user_prefs,
            locale="es-ES",
            # Spanish government portals use CAs (FNMT, etc.) not always in
            # Firefox's built-in trust store.
            ignore_https_errors=True,
        )
        if firefox_profile_dir is not None:
            firefox_profile_dir.mkdir(parents=True, exist_ok=True)
            launch_kwargs["user_data_dir"] = str(firefox_profile_dir)

        context = await p.firefox.launch_persistent_context(**launch_kwargs)
        # launch_persistent_context already opens one blank tab — reuse it
        # rather than opening a second window with new_page().
        page = context.pages[0] if context.pages else await context.new_page()

        try:
            if "/privada/" in target_path:
                # Portal de Transparencia — navigate to Cl@ve proxy endpoint directly
                return_url = urllib.parse.quote(f"{portal_url}{target_path}")
                await page.goto(
                    f"{portal_url}/claveproxy/clave/authenticate?returnUrl={return_url}",
                    wait_until="domcontentloaded",
                )
            else:
                # CTBG and other portals — multi-step login
                await page.goto(portal_url, wait_until="domcontentloaded")
                try:
                    await page.get_by_text("Identifícate").click(timeout=10_000)
                    console.print("[dim]Clic en 'Identifícate'[/]")
                except Exception:
                    console.print("[dim]No se encontró enlace 'Identifícate'[/]")

                try:
                    await page.locator('a:has-text("Cl@ve")').click(timeout=10_000)
                    console.print("[dim]Clic en 'Acceso con sistema Cl@ve'[/]")
                except Exception:
                    console.print("[dim]No se encontró enlace 'Cl@ve' — selecciónalo manualmente[/]")

            # Auto-click "DNIe / Certificado electrónico" (AFIRMA IdP)
            try:
                await page.locator('button[onclick*="AFIRMA"]').click(timeout=10_000)
                console.print("[dim]Método de autenticación seleccionado automáticamente[/]")
            except Exception:
                console.print("[dim]No se pudo seleccionar el método automáticamente — selecciónalo manualmente[/]")

            if not profile_ready:
                console.print("[bold cyan]Esperando autenticación...[/]")

            await page.wait_for_url(
                f"{portal_url}/**",
                timeout=timeout_seconds * 1000,
            )

            console.print("[bold green]Autenticación completada[/]")

            cookies = await context.cookies(portal_url)
            if firefox_profile_dir is not None:
                (firefox_profile_dir / ".pideinfo-cert-ready").touch()
            return _cookies_to_dict(cookies)

        except Exception as e:
            error_msg = str(e)
            if "Timeout" in error_msg:
                raise AuthTimeoutError(
                    f"Timeout de autenticación ({timeout_seconds}s). "
                    "¿Has completado la selección de certificado?"
                ) from e
            if "Target closed" in error_msg or "Browser closed" in error_msg:
                raise AuthCancelledError(
                    "El navegador fue cerrado antes de completar la autenticación"
                ) from e
            raise AuthFailedError(f"Error de autenticación: {error_msg}") from e

        finally:
            await context.close()


def _cookies_to_dict(cookies: list[dict[str, Any]]) -> dict[str, str]:
    """Convert Playwright cookie list to a simple name→value dict."""
    return {cookie["name"]: cookie["value"] for cookie in cookies}
