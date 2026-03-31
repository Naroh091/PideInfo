"""
DEHú / RedSARA authentication via Cl@ve + Bearer JWT capture.

This is a standalone auth function distinct from playwright_auth.authenticate()
because after the Cl@ve flow lands us on the DEHú home page we must navigate
further to trigger the Angular app's first API call — which is the only moment
the Bearer JWT is emitted and can be captured.
"""
from __future__ import annotations

from pathlib import Path
from typing import Any

from playwright.async_api import async_playwright
from rich.console import Console

console = Console()


async def authenticate_dehu(
    portal_url: str,
    firefox_profile_dir: Path,
    timeout_seconds: int = 120,
) -> tuple[dict[str, str], str]:
    """
    Authenticate to DEHú via Cl@ve and capture the short-lived Bearer JWT.

    Launches a persistent Firefox profile (same ``security.osclientcerts.autoload``
    trick as the main portal auth) so the OS certificate selection is remembered
    after the first run.

    The Angular frontend issues a Bearer JWT when it makes its first API request.
    We intercept that request via ``context.on("request", ...)`` to capture the
    token before closing the browser context.

    Returns:
        (cookies, jwt_token) — ``jwt_token`` is ``""`` if capture failed.
    """
    # First launch needs a visible window so the user can pick their certificate
    # from Firefox's native cert picker.  Once the persistent profile has been
    # initialised (prefs.js exists) the choice is remembered and we can run
    # headless — no UI required.
    firefox_profile_dir.mkdir(parents=True, exist_ok=True)
    profile_ready = (firefox_profile_dir / "prefs.js").exists()

    if profile_ready:
        console.print("[dim]DEHú: autenticando Cl@ve en segundo plano...[/]")
    else:
        console.print("[bold yellow]DEHú: abriendo navegador para autenticación Cl@ve...[/]")
        console.print("[dim]Primera vez: elige tu certificado. A partir de ahora será silencioso.[/]")
    console.print(f"[dim]Timeout: {timeout_seconds}s[/]")

    firefox_user_prefs: dict[str, Any] = {
        "security.osclientcerts.autoload": True,
        "browser.sessionstore.resume_from_crash": False,
        "browser.startup.page": 0,
    }

    captured_jwt: list[str] = []

    async with async_playwright() as p:
        context = await p.firefox.launch_persistent_context(
            user_data_dir=str(firefox_profile_dir),
            headless=profile_ready,
            firefox_user_prefs=firefox_user_prefs,
            locale="es-ES",
            ignore_https_errors=True,
        )

        # Attach JWT capture listener BEFORE any navigation so we don't miss it
        def _capture_bearer(request) -> None:
            if captured_jwt:
                return
            auth = request.headers.get("authorization", "")
            if auth.startswith("Bearer ") and "/api/v1/" in request.url:
                captured_jwt.append(auth[len("Bearer "):])

        context.on("request", _capture_bearer)

        page = context.pages[0] if context.pages else await context.new_page()

        try:
            # Navigate to the Cl@ve entry point for DEHú
            await page.goto(portal_url, wait_until="domcontentloaded")

            # Try to trigger Cl@ve authentication flow
            try:
                # Look for a login / access button
                await page.locator('a[href*="login"], button:has-text("Acceder"), a:has-text("Acceder"), a:has-text("Identificarse")').first.click(timeout=8_000)
                console.print("[dim]DEHú: clic en botón de acceso[/]")
            except Exception:
                console.print("[dim]DEHú: no se encontró botón de acceso — continuando[/]")

            # Auto-click "DNIe / Certificado electrónico" (AFIRMA IdP)
            try:
                await page.locator('button[onclick*="AFIRMA"], a[onclick*="AFIRMA"]').click(timeout=10_000)
                console.print("[dim]DEHú: método AFIRMA seleccionado automáticamente[/]")
            except Exception:
                console.print("[dim]DEHú: no se pudo seleccionar AFIRMA — selecciónalo manualmente[/]")

            if not profile_ready:
                console.print("[bold cyan]DEHú: esperando autenticación...[/]")

            # Wait until we land on the DEHú portal
            await page.wait_for_url(
                f"{portal_url}/**",
                timeout=timeout_seconds * 1000,
            )
            console.print("[bold green]DEHú: autenticación completada[/]")

            # Navigate to notifications page — this triggers Angular to call
            # GET /api/v1/notifications? with the Bearer token we want to capture.
            # Use domcontentloaded (fast) then poll briefly for the JWT rather than
            # waiting for networkidle, which times out on Angular SPAs that keep
            # long-polling connections open.
            console.print("[dim]DEHú: navegando a notificaciones para capturar JWT...[/]")
            import asyncio
            try:
                await page.goto(
                    f"{portal_url}/es/notifications",
                    wait_until="domcontentloaded",
                    timeout=15_000,
                )
            except Exception as e:
                console.print(f"[dim]DEHú: {e} — continuando[/]")

            # Poll up to 8 seconds for the Angular XHR to fire
            for _ in range(80):
                if captured_jwt:
                    break
                await asyncio.sleep(0.1)

            if captured_jwt:
                console.print("[green]DEHú: JWT capturado correctamente[/]")
            else:
                console.print("[yellow]DEHú: no se pudo capturar el JWT — las llamadas API fallarán[/]")

            cookies_list = await context.cookies(portal_url)
            cookies = {c["name"]: c["value"] for c in cookies_list}
            jwt = captured_jwt[0] if captured_jwt else ""
            return cookies, jwt

        except Exception as e:
            error_msg = str(e)
            if "Timeout" in error_msg:
                from auth.playwright_auth import AuthTimeoutError
                raise AuthTimeoutError(
                    f"DEHú: timeout de autenticación ({timeout_seconds}s)"
                ) from e
            if "Target closed" in error_msg or "Browser closed" in error_msg:
                from auth.playwright_auth import AuthCancelledError
                raise AuthCancelledError(
                    "DEHú: el navegador fue cerrado antes de completar la autenticación"
                ) from e
            from auth.playwright_auth import AuthFailedError
            raise AuthFailedError(f"DEHú: error de autenticación: {error_msg}") from e

        finally:
            await context.close()
