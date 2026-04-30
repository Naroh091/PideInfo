"""
Red SARA REC (Registro Electrónico Común) authentication via Cl@ve + Bearer JWT capture.

Similar to DEHú auth but targets reg.redsara.es.  After the Cl@ve flow lands on
the Red SARA home page we navigate to ``/es/mis-registros`` to trigger the Angular
app's first API call to ``reg-api.redsara.es`` — which emits the Bearer JWT.
"""
from __future__ import annotations

from pathlib import Path
from typing import Any

from playwright.async_api import async_playwright
from rich.console import Console

console = Console()

_API_DOMAIN = "redsara.es"  # catch any subdomain, including auth server refresh calls


async def authenticate_redsara(
    portal_url: str,
    firefox_profile_dir: Path,
    timeout_seconds: int = 120,
    headless: bool = True,
) -> tuple[dict[str, str], str, str]:
    """
    Authenticate to Red SARA via Cl@ve and capture the short-lived Bearer JWT.

    Returns:
        (cookies, api_jwt, download_jwt) where:
        - ``api_jwt`` is the RS256 ROLE_API token for search/list calls (``""`` if failed)
        - ``download_jwt`` is the HS256 ROLE_USER token for document downloads (``""`` if failed)
    """
    firefox_profile_dir.mkdir(parents=True, exist_ok=True)
    profile_ready = (firefox_profile_dir / "prefs.js").exists()

    if profile_ready:
        console.print("[dim]Red SARA: autenticando Cl@ve en segundo plano...[/]")
    else:
        console.print("[bold yellow]Red SARA: abriendo navegador para autenticación Cl@ve...[/]")
        console.print("[dim]Primera vez: elige tu certificado. A partir de ahora será silencioso.[/]")
    console.print(f"[dim]Timeout: {timeout_seconds}s[/]")

    firefox_user_prefs: dict[str, Any] = {
        "security.osclientcerts.autoload": True,
        "browser.sessionstore.resume_from_crash": False,
        "browser.startup.page": 0,
    }
    cert_ready = (firefox_profile_dir / ".pideinfo-cert-ready").exists()
    if headless or cert_ready:
        firefox_user_prefs["security.default_personal_cert"] = "Select Automatically"

    captured_jwt: list[str] = []       # RS256 ROLE_API — for search/list API calls
    captured_download_jwt: list[str] = []  # HS256 ROLE_USER — for document downloads

    async with async_playwright() as p:
        context = await p.firefox.launch_persistent_context(
            user_data_dir=str(firefox_profile_dir),
            headless=headless,
            firefox_user_prefs=firefox_user_prefs,
            locale="es-ES",
            ignore_https_errors=True,
        )

        # Attach JWT capture listener BEFORE any navigation.
        # The search/list API uses a short-lived RS256 ROLE_API token (~10 min)
        # that the Angular frontend obtains and sends in XHR calls.
        # We capture the first JWT we see to redsara.es — this is the ROLE_API token.
        def _capture_bearer(request) -> None:
            if captured_jwt:
                return
            auth = request.headers.get("authorization", "")
            if auth.startswith("Bearer ") and _API_DOMAIN in request.url:
                token = auth[len("Bearer "):]
                # Only capture real JWTs (three base64url parts), not opaque tokens
                if token.startswith("ey") and token.count(".") == 2:
                    captured_jwt.append(token)

        context.on("request", _capture_bearer)

        page = context.pages[0] if context.pages else await context.new_page()

        import asyncio

        try:
            # Navigate to Red SARA login page
            await page.goto(f"{portal_url}/es/login", wait_until="domcontentloaded")

            # If already authenticated (Angular app loaded with user session),
            # look for logged-in indicators and skip the login flow.
            already_logged_in = False
            try:
                await page.locator('dnt-header-item[text="Iniciar sesión"]').wait_for(
                    state="visible", timeout=5_000
                )
            except Exception:
                # Button not found — likely already logged in
                already_logged_in = True
                console.print("[dim]Red SARA: sesión activa detectada[/]")

            if not already_logged_in:
                # Click "Iniciar sesión" web component
                try:
                    await page.locator(
                        'dnt-header-item[text="Iniciar sesión"], '
                        'dnt-button:has-text("Accede con tu Cl@ve"), '
                        'button:has-text("Accede con tu Cl@ve"), '
                        'a:has-text("Iniciar sesión"), '
                        'button:has-text("Iniciar sesión")'
                    ).first.click(timeout=10_000)
                    console.print("[dim]Red SARA: clic en botón de acceso[/]")
                except Exception:
                    console.print("[dim]Red SARA: no se encontró botón de acceso — continuando[/]")

                # Auto-click "DNIe / Certificado electrónico" (AFIRMA IdP)
                try:
                    await page.locator('button[onclick*="AFIRMA"], a[onclick*="AFIRMA"]').click(timeout=10_000)
                    console.print("[dim]Red SARA: método AFIRMA seleccionado automáticamente[/]")
                except Exception:
                    console.print("[dim]Red SARA: no se pudo seleccionar AFIRMA — selecciónalo manualmente[/]")

                if not profile_ready:
                    console.print("[bold cyan]Red SARA: esperando autenticación...[/]")

                # Wait until we return to the Red SARA portal after Cl@ve auth.
                # Use wait_for_url with a predicate that checks we left and came back,
                # or simply wait for a URL that is NOT the login/public page.
                await page.wait_for_url(
                    lambda url: portal_url in url and "/es/login" not in url and "/public" not in url,
                    timeout=timeout_seconds * 1000,
                )

            console.print("[bold green]Red SARA: autenticación completada[/]")
            (firefox_profile_dir / ".pideinfo-cert-ready").touch()
            await asyncio.sleep(1.5)

            # Navigate to "mis registros" — this triggers Angular to call
            # the reg-api with the Bearer token we want to capture.
            console.print("[dim]Red SARA: navegando a mis registros para capturar JWT...[/]")
            try:
                await page.goto(
                    f"{portal_url}/es/mis-registros",
                    wait_until="domcontentloaded",
                    timeout=15_000,
                )
            except Exception as e:
                console.print(f"[dim]Red SARA: {e} — continuando[/]")

            # Poll up to 12 seconds for the Angular XHR to fire
            for _ in range(120):
                if captured_jwt:
                    break
                await asyncio.sleep(0.1)

            # Extract document download JWT from localStorage['access_token'].
            # This HS256 ROLE_USER token is used for /documents/uuid/... downloads.
            # Also serves as fallback for the search JWT if the network listener missed it.
            try:
                js_result = await page.evaluate("""() => {
                    const access = localStorage.getItem('access_token') || '';
                    // Scan all storage for any other JWT-shaped value (search token)
                    let other = '';
                    for (const s of [sessionStorage, localStorage]) {
                        for (let i = 0; i < s.length; i++) {
                            const k = s.key(i);
                            const v = s.getItem(k);
                            if (v && v.startsWith('ey') && v.split('.').length === 3 && v !== access) {
                                other = v;
                            }
                        }
                    }
                    return { access_token: access, other_token: other };
                }""")
                access_token = js_result.get("access_token", "") if js_result else ""
                other_token = js_result.get("other_token", "") if js_result else ""

                if access_token and access_token.startswith("ey") and access_token.count(".") == 2:
                    captured_download_jwt.append(access_token)
                    console.print("[green]Red SARA: download JWT extraído de localStorage[/]")

                # If network listener didn't fire, use any other JWT as search token
                if not captured_jwt and other_token and other_token.startswith("ey") and other_token.count(".") == 2:
                    captured_jwt.append(other_token)
                    console.print("[green]Red SARA: search JWT extraído de localStorage (fallback)[/]")
                elif not captured_jwt and access_token:
                    # Last resort: use the access_token for search too
                    captured_jwt.append(access_token)
                    console.print("[yellow]Red SARA: usando access_token como search JWT (fallback)[/]")
            except Exception as e:
                console.print(f"[dim]Red SARA: no se pudo leer localStorage: {e}[/]")

            if captured_jwt:
                console.print("[green]Red SARA: JWT de búsqueda capturado correctamente[/]")
            else:
                console.print("[yellow]Red SARA: no se pudo capturar el JWT de búsqueda — las llamadas API fallarán[/]")

            if not captured_download_jwt:
                console.print("[yellow]Red SARA: no se pudo capturar el JWT de descarga[/]")

            cookies_list = await context.cookies(portal_url)
            cookies = {c["name"]: c["value"] for c in cookies_list}
            jwt = captured_jwt[0] if captured_jwt else ""
            download_jwt = captured_download_jwt[0] if captured_download_jwt else ""
            return cookies, jwt, download_jwt

        except Exception as e:
            error_msg = str(e)
            if "Timeout" in error_msg:
                from auth.playwright_auth import AuthTimeoutError
                raise AuthTimeoutError(
                    f"Red SARA: timeout de autenticación ({timeout_seconds}s)"
                ) from e
            if "Target closed" in error_msg or "Browser closed" in error_msg:
                from auth.playwright_auth import AuthCancelledError
                raise AuthCancelledError(
                    "Red SARA: el navegador fue cerrado antes de completar la autenticación"
                ) from e
            from auth.playwright_auth import AuthFailedError
            raise AuthFailedError(f"Red SARA: error de autenticación: {error_msg}") from e

        finally:
            await context.close()
