# MCP / OAuth2 caveats

Cosas no obvias que aprendimos integrando clientes MCP (Claude Code, Claude Desktop, Hermes, Codex CLI…) contra `pideinfo.es/mcp`. Si reapareces aquí porque algo no conecta, repasa esta lista antes de bucear en el bundle.

## 1. `offline_access` no se anuncia en `oauth-protected-resource`, pero los clientes lo piden igual

`/.well-known/oauth-protected-resource` solo declara los scopes "de recurso" (`mcp:read mcp:write mcp:documents`). `offline_access` es un meta-scope que controla la emisión de refresh tokens, no el acceso a un recurso, así que queda fuera.

Lo que hacen los clientes MCP: leen ese metadata, mandan **DCR sin `offline_access`** y luego en `/oauth2/authorize` añaden `offline_access` por su cuenta para conseguir refresh token. Resultado:

- `validateScopes` en `/authorize` pasa (el scope sí está en la lista global).
- `/oauth2/token` ejecuta `finalizeScopes`, que valida contra los scopes **del cliente**, y revienta con `invalid_scope` ("The requested scope is invalid, unknown, or malformed").

**Fix vivo en el código** (`src/Controller/OAuth2/ClientRegistrationController.php`): si DCR pide `refresh_token` como `grant_types`, auto-añadimos `offline_access` a los scopes del cliente aunque no venga en `scope`. No tocar sin entender esto.

## 2. El JWT subject debe ser UUID, no email

`User::getUserIdentifier()` devuelve el email (es lo que usa el firewall `main` de Symfony — `security.yaml`). Si dejas que league use eso como `sub`, `App\Security\OAuth2\OAuth2TokenHandler::64` rechaza el bearer con `OAuth2 token user identifier is not a valid UUID` porque hace `Uuid::isValid($sub)`.

**Fix vivo**: `App\Security\OAuth2\UserConverter` decora el converter del bundle y mete `getId()->toRfc4122()` como identifier. El login web sigue por email; el JWT sigue por UUID. Si tocas `User` o el converter, asegúrate de no romper esta separación.

## 3. La pantalla de consent NO puede pasar por Turbo

`templates/oauth2/consent.html.twig` lleva `data-turbo="false"` en el `<form>`. Sin eso:

- El POST se hace por `fetch` (Turbo Drive).
- El servidor devuelve 302 a `redirect_uri` (que para clientes MCP es `http://localhost:<port>/callback`).
- `fetch` sigue el redirect cross-origin → CORS preflight → bloqueado.
- El usuario ve "ha fallado" y el code se queda colgado.

Con `data-turbo="false"` el navegador hace navegación HTML clásica, sigue el 302 sin CORS y el cliente recibe su `code`. **Cualquier nuevo formulario que haga POST y devuelva un redirect cross-origin necesita lo mismo.**

## 4. Puertos "unsafe" del navegador

Chrome/Firefox bloquean la navegación a una lista de puertos restringidos: 1, 7, 9, 11, 13, 15, 17, 19-23, 25, 37, 42, 43, 53, 69, 77, 79, 87, 95, 101-104, 109-111, 113, 115, 117, 119, 123, 135, 137, 139, 143, 161, 179, 389, 427, 465, 512-515, 526, 530-532, 540, 548, 554, 556, 563, 587, 601, 636, 989, 990, 993, 995, 1719, 1720, 1723, 2049, 3659, 4045, 5060, 5061, 6000, 6566, 6665-6669, 6697.

Si un cliente DCR registra un `redirect_uri` con uno de esos puertos (típico: `localhost:1` como placeholder), el navegador no puede seguir el redirect tras el consent. Validar puerto en el DCR sería excesivo (rompe usos legítimos como `localhost:80`); lo razonable es documentarlo. Para diagnosticar: si ves "unsafe port" en consola del navegador, el cliente eligió mal — re-registrar con puerto >= 1024 fuera de la blocklist (8080, 8765, 53682, etc.).

## 5. Authorization codes son single-use

OAuth 6749 §4.1.2: el servidor invalida el code en el primer uso, exitoso o no. Si un cliente automatizado entra en bucle de "fallo → re-authorize → re-authorize → …" quema un code por intento. Síntoma típico: el primer POST a `/oauth2/token` devolvió 200 y guardó tokens; el segundo da 400 porque el code ya está revocado. Antes de repetir authorize **siempre intenta refresh** primero.

## 6. Cloudflare delante del MCP

Producción está detrás de Cloudflare. WAF rules bloquean (1010 / 1020) requests con:

- `User-Agent` ausente o vacío
- patrones de bot conocidos
- ciertas combinaciones de headers

Si ves error 1010 al llamar a `/oauth2/token` o `/mcp`, el cliente no está mandando `User-Agent`. Cualquier identificador no vacío basta (`hermes-mcp/1.0`, `codex-cli/0.1`, etc.). El requisito está en las `instructions` que devuelve el MCP server (`config/packages/mcp.yaml`) precisamente para que los agentes lo pongan sin que el humano tenga que recordarlo.

## 7. Cómo reproducir el flujo entero contra dev

1. `symfony serve -d` (ya hay HTTPS local con cert de mkcert).
2. En el cliente MCP: apuntar a `https://127.0.0.1:8000/mcp`.
3. `tail -F var/log/dev.log | grep -iE "oauth2|/mcp|scope"` para ver el handshake.
4. Si quieres saber qué scope/payload manda un cliente, revisa los `INSERT INTO oauth2_client` (DCR) y `INSERT INTO oauth2_authorization_code` que aparecen en el log de Doctrine: ahí están literalmente los strings que envió el cliente.

Si el flujo se rompe en un sitio nuevo, **primero confirma cuál de los pasos falla** (DCR / authorize / token / `/mcp`) leyendo el log antes de tocar código. Cada paso tiene su propio `OAuthServerException`-itis distinto y las pistas de la UI del cliente son siempre genéricas.
