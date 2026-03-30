from __future__ import annotations

import asyncio
import urllib.parse
from typing import Any

from playwright.async_api import async_playwright, BrowserContext
from rich.console import Console

console = Console()


class AuthTimeoutError(Exception):
    pass


class AuthCancelledError(Exception):
    pass


class AuthFailedError(Exception):
    pass


async def authenticate(portal_url: str, timeout_seconds: int = 120) -> dict[str, str]:
    """
    Open a headed browser, navigate to the portal's private area,
    wait for the user to complete Cl@ve authentication with their certificate,
    and return the session cookies.
    """
    console.print("[bold yellow]Abriendo navegador para autenticación Cl@ve...[/]")
    console.print(
        f"[dim]Timeout: {timeout_seconds}s — selecciona tu certificado e introduce el PIN[/]"
    )

    async with async_playwright() as p:
        browser = await p.chromium.launch(headless=False)
        context = await browser.new_context(
            locale="es-ES",
            user_agent=(
                "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
                "AppleWebKit/537.36 (KHTML, like Gecko) "
                "Chrome/131.0.0.0 Safari/537.36"
            ),
        )
        page = await context.new_page()

        try:
            # Navigate directly to the Cl@ve authentication endpoint.
            # The portal does NOT auto-redirect — it shows a 401 page with an
            # explicit login link pointing to /claveproxy/clave/authenticate.
            return_url = urllib.parse.quote(f"{portal_url}/privada/expedientes")
            await page.goto(
                f"{portal_url}/claveproxy/clave/authenticate?returnUrl={return_url}",
                wait_until="domcontentloaded",
            )

            console.print("[bold cyan]Esperando autenticación...[/]")

            # Wait for the user to complete auth and be redirected back to the portal
            await page.wait_for_url(
                f"{portal_url}/privada/**",
                timeout=timeout_seconds * 1000,
            )

            console.print("[bold green]Autenticación completada[/]")

            # Extract cookies for the portal domain
            cookies = await context.cookies(portal_url)
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
            await browser.close()


def _cookies_to_dict(cookies: list[dict[str, Any]]) -> dict[str, str]:
    """Convert Playwright cookie list to a simple name→value dict."""
    return {cookie["name"]: cookie["value"] for cookie in cookies}
