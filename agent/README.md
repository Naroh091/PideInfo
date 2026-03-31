# PideInfo Agent

Aplicación de escritorio que sincroniza automáticamente el **Portal de Transparencia de la AGE**, la **sede electrónica del Consejo de Transparencia y Buen Gobierno (CTBG)** y **DEHú / RedSARA** con el backend de PideInfo.

El agente se ejecuta en segundo plano como icono en la barra del sistema (macOS), en la bandeja del sistema (Windows) o como AppIndicator (Linux). Autentica al usuario mediante el sistema Cl@ve, descarga documentos de las solicitudes de acceso a información y los envía a PideInfo vía webhook.

---

## Índice

1. [Arquitectura general](#arquitectura-general)
2. [Estructura del proyecto](#estructura-del-proyecto)
3. [Flujos principales](#flujos-principales)
4. [Seguridad](#seguridad)
5. [Autenticación con los portales](#autenticación-con-los-portales)
6. [Ciclo de sincronización](#ciclo-de-sincronización)
7. [Integración con PideInfo](#integración-con-pideinfo)
8. [Interfaz de escritorio](#interfaz-de-escritorio)
9. [Configuración](#configuración)
10. [Empaquetado como aplicación de escritorio](#empaquetado-como-aplicación-de-escritorio)
11. [Actualización automática](#actualización-automática)
12. [CI/CD](#cicd)
13. [Requisitos e instalación en desarrollo](#requisitos-e-instalación-en-desarrollo)

---

## Arquitectura general

```
┌─────────────────────────────────────────────────────────────────┐
│                         PideInfo Agent                          │
│                                                                 │
│  ┌──────────┐    ┌──────────────┐    ┌────────────────────────┐ │
│  │  Tray UI │    │  main.py     │    │  APScheduler           │ │
│  │ (pystray)│───▶│  CLI entry   │───▶│  (daemon / cada 30 min)│ │
│  └──────────┘    └──────┬───────┘    └───────────┬────────────┘ │
│                         │                        │              │
│              ┌──────────▼────────────────────────▼───────────┐  │
│              │              do_sync()                         │  │
│              └──────┬────────────────────────────────────────┘  │
│                     │                                           │
│          ┌──────────▼────────────────────────────────┐          │
│          │           Portales (scrapers)              │          │
│          │  ┌──────────────────┐  ┌────────────────┐  ┌───────────────┐ │ │
│          │  │TransparenciaAGE  │  │ ConsejoScraper │  │  DehuScraper  │ │ │
│          │  │  (httpx + regex) │  │ (BS4 + httpx)  │  │ (httpx + JWT) │ │ │
│          │  └────────┬─────────┘  └───────┬────────┘  └──────┬────────┘ │ │
│          └───────────┼────────────────────┼──────────────────┼──────────┘ │
│                      │                    │                   │            │
│          ┌───────────▼────────────────────▼───────────────────▼──────────┐│
│          │                  PideInfoClient (JWT + httpx)                  ││
│          │                   POST /api/agent/webhook                      ││
│          └────────────────────────────────────────────────────────────────┘│
└──────────────────────────────────────────────────────────────────────────────┘
         │                    │                      │
         ▼                    ▼                      ▼
Portal Transparencia    CTBG sede           DEHú / RedSARA
 AGE transparencia   consejodetrans-       dehu.redsara.es
 .sede.gob.es        parencia.gob.es       (REST API + JWT)
```

El agente es **sin servidor**: no expone puertos, no tiene base de datos propia. Todo el estado persistente (cookies de sesión, documentos ya sincronizados, token JWT) se guarda localmente en `~/.pideinfo-agent/`.

---

## Estructura del proyecto

```
agent/
├── main.py                  # Punto de entrada CLI y orquestación de sincronización
├── tray.py                  # Interfaz de bandeja del sistema (pystray + Pillow)
├── config.py                # Configuración (pydantic-settings, .env)
├── version.py               # Versión única: __version__ = "0.0.1"
├── runtime.py               # Helpers para entornos frozen (PyInstaller)
│
├── auth/
│   ├── playwright_auth.py        # Auth Cl@ve vía Firefox (headless tras 1ª vez)
│   ├── session_manager.py        # Gestión de cookies de sesión con OS keyring
│   ├── dehu_auth.py              # Auth DEHú: igual que anterior + captura de JWT
│   └── dehu_session_manager.py   # Cookies + JWT para DEHú (verificación de expiración)
│
├── portals/
│   ├── base.py              # Protocolo PortalScraper
│   ├── transparencia_age.py # Scraper del Portal de Transparencia AGE
│   ├── consejo_ctbg.py      # Scraper de la sede del CTBG
│   └── dehu_redsara.py      # Scraper DEHú (REST API JSON + Bearer JWT)
│
├── client/
│   └── pideinfo.py          # Cliente HTTP para el webhook de PideInfo
│
├── models/
│   ├── portal.py            # Expediente, Notificacion, DocumentoExpediente
│   ├── consejo.py           # ConsejoNotificacion
│   └── dehu.py              # DehuNotificacion
│
├── storage/
│   ├── state.py             # Estado de sincronización (docs ya enviados)
│   ├── preferences.py       # Preferencias del usuario (JWT, cert)
│   └── downloads.py         # Gestión de archivos descargados temporalmente
│
├── notifier/
│   └── desktop.py           # Notificaciones de escritorio del sistema
│
├── ui/
│   └── connect_dialog.py    # Diálogos de conexión con PideInfo
│
├── updater/
│   └── github_updater.py    # Comprobación y descarga de actualizaciones
│
├── build/
│   ├── pideinfo-agent.spec  # Spec de PyInstaller (todas las plataformas)
│   ├── hooks/               # PyInstaller hooks (playwright, truststore)
│   ├── macos/               # entitlements.plist para firma de código
│   ├── windows/             # installer.nsi (NSIS)
│   └── linux/               # .desktop + AppRun para AppImage
│
├── requirements.txt
├── pyproject.toml
└── .env.example
```

---

## Flujos principales

### 1. Inicio de la aplicación

```
main.py
  │
  ├─ setup_playwright_env()          ← fija PLAYWRIGHT_BROWSERS_PATH antes de
  │                                    importar playwright en ningún módulo
  ├─ Settings(_env_file=...)         ← carga .env + variables de entorno
  ├─ ensure_firefox()                ← descarga Firefox si no está instalado
  │                                    (solo ocurre la primera vez, ~80 MB)
  └─ modo de ejecución:
       --tray    → _run_tray()       ← abre icono en barra del sistema
       --daemon  → do_daemon()       ← sincronización periódica con APScheduler
       --once    → do_sync()         ← un solo ciclo de sincronización
       --auth-only → do_auth()       ← solo abre el navegador para autenticar
```

### 2. Flujo de autenticación con el portal

```
SessionManager.get_valid_session()
  │
  ├─ load_cookies()           ← lee metadata de disco, secretos del OS keyring
  │    └─ is_session_valid()  ← GET /privada/expedientes (no follow redirect)
  │         ├─ 200 OK  → sesión válida, continúa
  │         └─ redirect → sesión expirada
  │
  └─ (si no hay cookies válidas)
       playwright_auth.authenticate()
         │
         ├─ Comprueba si firefox-profile/prefs.js existe
         │    ├─ NO (1ª vez) → Firefox en modo headed (ventana visible)
         │    │                  El usuario elige su certificado en el selector de Firefox
         │    │                  → Firefox lo guarda en el perfil persistente
         │    └─ SÍ (usos posteriores) → Firefox en modo headless (sin ventana)
         │                  El certificado se selecciona automáticamente del perfil
         │                  El usuario no ve ni interactúa con nada
         ├─ security.osclientcerts.autoload: true
         │    ← Firefox carga los certs directamente del Keychain del SO
         │       sin extraer ni copiar la clave privada
         ├─ Navega a /claveproxy/clave/authenticate?returnUrl=...
         ├─ Auto-click en "DNIe / Certificado electrónico" (AFIRMA IdP)
         ├─ Espera hasta que el usuario complete la auth (timeout: 120 s)
         └─ Extrae cookies del contexto del navegador
              └─ save_cookies() → valores al OS keyring, timestamp a disco
```

### 3. Ciclo de sincronización completo

```
do_sync()
  │
  ├─ 1. Portal de Transparencia AGE ─────────────────────────────────────
  │    ├─ GET /privada/expedientes (paginado, 15/página)
  │    │    └─ Extrae JSON de <input id="expedientesData" type="hidden">
  │    ├─ GET /privada/notificaciones (paginado)
  │    │    └─ Extrae JSON de <input id="notificacionesData" type="hidden">
  │    │
  │    ├─ Para cada expediente (no AC1, no ACCEDA, no borrador):
  │    │    ├─ GET /privada/expediente?id=N
  │    │    ├─ Extrae documentos de <input id="documentosData">
  │    │    ├─ Filtra los no sincronizados (state.is_document_synced)
  │    │    ├─ Descarga todos a ~/.pideinfo-agent/downloads/
  │    │    └─ POST /api/agent/webhook (batch, SOLICITUD primero)
  │    │         └─ Documentos en base64 + SHA-256 + metadatos del expediente
  │    │
  │    ├─ Para cada notificación descargable (FIRMADA, LEIDA, EXPIRADA):
  │    │    ├─ Descarga el documento
  │    │    └─ POST /api/agent/webhook (individual + metadatos de notificación)
  │    │
  │    └─ Reporta notificaciones PENDIENTE a PideInfo (sin descargar)
  │         └─ POST con pendingNotifications[] para que PideInfo las muestre al usuario
  │
  ├─ 2. Guarda estado (state.json) ─────────────────────────────────────
  │
  ├─ 3. CTBG (Consejo de Transparencia) ────────────────────────────────
  │    ├─ Sesión independiente con cookies propias
  │    ├─ GET /enotifications.9 (Wicket framework, tabla HTML)
  │    ├─ BeautifulSoup parsea ElectronicMailboxListPanel
  │    └─ Reporta pendientes a PideInfo vía webhook (source: consejo_ctbg)
  │
  └─ 4. DEHú / RedSARA ─────────────────────────────────────────────────
       ├─ DehuSessionManager comprueba expiración del JWT en local
       │    (decodifica base64 el payload, lee claim exp — sin red)
       │    ├─ JWT válido → reutiliza cookies + JWT del keyring
       │    └─ JWT expirado/ausente → re-auth Firefox (headless si perfil ya existe)
       │         └─ context.on("request") captura Bearer JWT del primer XHR Angular
       ├─ GET /api/v1/notifications (httpx + Authorization: Bearer <jwt>)
       └─ Reporta notificaciones pendientes a PideInfo (source: dehu_redsara)
```

### 4. Deduplicación de documentos

El estado de sincronización se persiste en `~/.pideinfo-agent/sync_state.json`:

```json
{
  "synced_documents": ["12345:67890", "12345:67891"],
  "synced_notification_ids": [111, 222],
  "pending_notification_expediente_ids": ["12345"],
  "ctbg_pending_expediente_refs": ["2026-EXP-001"],
  "dehu_pending_sent_references": ["9e177342ba..."],
  "last_sync": "2026-03-31T10:00:00"
}
```

La clave de deduplicación es `{id_expediente}:{id_documento}`. Un documento nunca se descarga ni envía dos veces, incluso si el agente se interrumpe a mitad de un ciclo.

---

## Seguridad

### Almacenamiento de credenciales

| Dato | Dónde se almacena |
|---|---|
| Token JWT de PideInfo | **OS keyring** (`pideinfo-agent / pideinfo:jwt`) |
| Cookies de sesión — portal principal | **OS keyring** (`pideinfo-agent / cookies:cookies`) |
| Cookies de sesión — CTBG | **OS keyring** (`pideinfo-agent / cookies:cookies_ctbg`) |
| Cookies de sesión — DEHú | **OS keyring** (`pideinfo-agent / cookies:cookies_dehu`) |
| JWT Bearer de DEHú | **OS keyring** (`pideinfo-agent / dehu:jwt`) |
| Metadatos no sensibles (email, URL, timestamps) | `~/.pideinfo-agent/preferences.json` (permisos 600) |

Ningún secreto se escribe en disco. Las instalaciones antiguas que tengan `jwt_token` en `preferences.json` lo migran automáticamente al keyring la primera vez que el agente arranca.

El agente **no almacena ningún fichero de certificado**. Los certificados residen únicamente en el almacén de certificados del sistema operativo (macOS Keychain, Windows Certificate Store, NSS en Linux), donde los instala el propio usuario o el instalador de la FNMT/CERES. El directorio `~/.pideinfo-agent/` tiene permisos `0700`.

### Autenticación con certificado: decisión de diseño

El sistema Cl@ve requiere un **certificado de cliente** (DNIe o FNMT/CERES) para autenticar al ciudadano. A continuación se documenta la evolución hasta la solución actual.

#### Intento 1 — Chromium con `--auto-select-client-certificates`

La primera versión lanzaba Chromium (el navegador empaquetado por Playwright) con el flag `--auto-select-client-certificates`. Este flag suprime el diálogo solo cuando **exactamente un** certificado coincide con el origen. En la práctica, los usuarios españoles suelen tener instalados varios certificados (p. ej. un certificado FNMT y uno del DNIe), por lo que el diálogo seguía apareciendo en cada autenticación.

#### Intento 2 — Política `AutoSelectCertificateForUrls`

Chrome permite configurar la selección automática de certificados por origen mediante la política `AutoSelectCertificateForUrls`, que admite filtros por emisor o número de serie. Sin embargo, Playwright deshabilita explícitamente la carga de políticas de empresa en su Chromium por razones de aislamiento y reproducibilidad ([issue #32324](https://github.com/microsoft/playwright/issues/32324)). Los intentos de inyectarla vía `defaults write`, ficheros JSON en el directorio de políticas o `chromeOptions.localState` no tuvieron efecto.

#### Intento 3 — Exportar el certificado del Keychain

Otra alternativa era leer el certificado del Keychain de macOS mediante PyObjC (`SecItemExport`) y pasarlo a la API `clientCertificates` de Playwright, que sí funciona con Chromium. Esta opción se descartó porque implica extraer la clave privada del Keychain —aunque fuera en memoria y sin escribir nunca a disco— lo que va en contra del principio de mínima exposición de la clave privada.

#### Solución final — Firefox con perfil persistente

Firefox implementa la preferencia `security.osclientcerts.autoload` que le indica que cargue los certificados directamente desde el almacén de certificados del sistema operativo (macOS Keychain, Windows Certificate Store). A diferencia de exportar el certificado, **la clave privada nunca sale del Keychain**: Firefox delega las operaciones criptográficas TLS en la API del sistema, exactamente igual que lo haría cualquier otro navegador nativo.

Combinado con un **perfil persistente** (`launch_persistent_context`), Firefox recuerda qué certificado eligió el usuario en cada origen. El resultado es:

- **Primera autenticación**: Firefox abre una ventana visible. El usuario elige su certificado FNMT o DNIe una sola vez en el selector nativo de Firefox.
- **Autenticaciones posteriores**: Firefox se lanza en modo **headless** (sin ventana). El certificado se selecciona automáticamente del perfil guardado; el usuario no ve ni interactúa con nada.

La decisión entre headed/headless se toma comprobando si `firefox-profile/prefs.js` existe en disco antes de lanzar el navegador.

```python
context = await p.firefox.launch_persistent_context(
    user_data_dir=str(firefox_profile_dir),   # recuerda la elección entre sesiones
    firefox_user_prefs={
        "security.osclientcerts.autoload": True,  # usa certs del Keychain del SO
    },
)
```

El perfil de Firefox se guarda en `~/.pideinfo-agent/firefox-profile/`. Contiene únicamente la preferencia de certificado por origen (un hash SHA-1), no la clave privada ni ningún secreto exportable.

### Comunicación de red

- Todas las peticiones al portal usan **HTTPS**.
- `truststore.inject_into_ssl()` configura Python para usar el almacén de certificados del sistema operativo, lo que permite verificar los certificados de las CAs del gobierno español (FNMT, etc.) sin necesidad de añadirlos manualmente.
- Las peticiones al portal se realizan con `ignore_https_errors: True` solo en el contexto de Playwright (necesario para los IdP del sistema Cl@ve), no en las peticiones httpx post-autenticación.
- El webhook de PideInfo se autentica con un **Bearer token JWT** en cada petición.

### Superficie de ataque

- El agente **no expone ningún puerto de red**.
- No tiene acceso a información de otras solicitudes de otros usuarios (el token JWT acota el acceso al usuario autenticado).
- Los documentos descargados se almacenan en `~/.pideinfo-agent/downloads/` y se borran inmediatamente después de enviarlos al webhook.

---

## Autenticación con los portales

### Portal de Transparencia AGE

El portal usa el sistema **Cl@ve** de la Administración General del Estado como IdP. No existe una API pública; la autenticación es completamente browser-based:

1. El agente abre Firefox en modo headful (ventana visible), con un perfil persistente.
2. Navega a `/claveproxy/clave/authenticate?returnUrl=...` que redirige al IdP de Cl@ve.
3. Hace clic automático en el botón "DNIe / Certificado electrónico" (AFIRMA).
4. **Primera vez**: Firefox muestra su selector de certificado con los certificados del Keychain del sistema. El usuario elige el suyo.
5. **Siguientes veces**: Firefox selecciona el certificado automáticamente desde el perfil guardado.
6. El usuario completa la autenticación (PIN del certificado si el Keychain lo requiere).
7. Tras la redirección de vuelta al portal, se extraen las cookies de sesión.

Las cookies tienen una vida útil de varias horas. El agente las reutiliza en las siguientes sincronizaciones y solo vuelve a abrir el navegador cuando expiran.

### CTBG (Consejo de Transparencia)

La sede del CTBG usa el mismo sistema Cl@ve pero con un flujo ligeramente diferente (framework Wicket). El agente mantiene una **segunda sesión independiente** con su propio fichero de cookies (`cookies_ctbg.json`), ya que las cookies del portal principal no son válidas aquí.

### DEHú / RedSARA

DEHú expone una **REST API JSON** en lugar de páginas HTML. Pero añade un requisito: además de las cookies de sesión, cada petición a la API requiere un **Bearer JWT** de corta duración (~10 minutos) que emite la aplicación Angular en el frontend.

El agente captura este JWT interceptando las peticiones de red del propio navegador durante la autenticación:

```
authenticate_dehu()
  │
  ├─ context.on("request", listener)   ← escucha ANTES de navegar
  ├─ Auth Cl@ve (mismo flujo Firefox + perfil persistente)
  ├─ Navega a /es/notifications         ← Angular dispara GET /api/v1/notifications
  │    └─ listener captura el header Authorization: Bearer <jwt>
  └─ Devuelve (cookies, jwt)
```

El JWT se guarda en el keyring (`dehu:jwt`). Antes de cada sincronización, `DehuSessionManager` decodifica el payload en local (sin red) para comprobar el claim `exp`. Si ha expirado, lanza una re-autenticación headless completa.

---

## Ciclo de sincronización

### Prioridad de documentos

Dentro del webhook por expediente, los documentos se envían con la **SOLICITUD primero**. Esto es intencional: el backend de PideInfo analiza el primer documento del lote para crear el `AccessRequest` si no existe todavía, usando la referencia del expediente como identificador.

### Tipos de notificación

| Estado | ¿Se descarga? | ¿Se reporta como pendiente? |
|---|---|---|
| FIRMADA | Sí | No |
| LEIDA | Sí | No |
| EXPIRADA (Resolución) | Sí | No |
| PENDIENTE | Solo si `accept_notifications` está activo | Sí (siempre) |
| RECHAZADA | No | No |

### Limpieza de pendientes

Cuando un expediente que tenía notificaciones PENDIENTE ya no las tiene (el usuario las firmó o caducaron), el agente envía un webhook con `pendingNotifications: []` para que PideInfo elimine la alerta en su interfaz.

---

## Integración con PideInfo

Todos los documentos se envían a `POST /api/agent/webhook` con el siguiente esquema:

```json
{
  "source": "transparencia_age",
  "expedienteRef": "2026-EXP-001234",
  "documents": [
    {
      "filename": "SOLICITUD - 2026-EXP-001234.pdf",
      "contentType": "application/pdf",
      "content": "<base64>",
      "contentHash": "<sha256-hex>",
      "portalDate": "2026-01-15"
    }
  ],
  "metadata": { ... },
  "pendingNotifications": [ ... ]
}
```

El campo `contentHash` (SHA-256) permite al backend de PideInfo detectar duplicados sin necesidad de reenviar el contenido. El agente también mantiene su propia deduplicación local para evitar descargas innecesarias.

---

## Interfaz de escritorio

El agente usa **pystray** para el icono en la barra del sistema y **Pillow** para renderizar el icono. La interfaz es mínima por diseño: el agente no tiene ventana principal.

### Menú (estado conectado)

```
Sincronizar ahora
Resetear
────────────────
Aceptar notificaciones electrónicas  [✓/☐]
────────────────
Certificado ✓  (o "Configurar certificado…")
Desconectar
Conectado como usuario@ejemplo.com
────────────────
Actualizar a v1.2.0...              ← solo si hay actualización disponible
PideInfo Agent v0.0.1               ← versión actual (deshabilitado)
Cerrar
```

### Indicador de actividad

El icono cambia de color durante la sincronización:
- **Azul** (`#1D4ED8`) — en reposo
- **Ámbar** (`#F59E0B`) — sincronizando

### Modelo de concurrencia

El bucle asyncio vive en un **hilo daemon en segundo plano**. El hilo principal pertenece a pystray (obligatorio en macOS, que requiere que el runloop de Cocoa esté en el hilo principal). Los callbacks del menú envían corrutinas al bucle mediante `asyncio.run_coroutine_threadsafe()`.

En macOS, antes de iniciar el runloop de pystray, el agente configura `NSApplicationActivationPolicyAccessory` para que no aparezca en el Dock ni en Mission Control.

---

## Configuración

Todas las variables se leen de un fichero `.env` (por defecto `.env` en el directorio de trabajo) y pueden sobreescribirse con variables de entorno:

| Variable | Valor por defecto | Descripción |
|---|---|---|
| `PORTAL_URL` | `https://transparencia.sede.gob.es` | URL del Portal de Transparencia AGE |
| `PORTAL_CTBG` | `https://sede.consejodetransparencia.gob.es/info.0` | URL de la sede del CTBG |
| `PORTAL_DEHU` | `https://dehu.redsara.es` | URL del portal DEHú / RedSARA |
| `PIDEINFO_BASE_URL` | `http://localhost:8000` | URL base del backend de PideInfo |
| `AUTH_TIMEOUT_SECONDS` | `120` | Segundos de espera para que el usuario complete la auth |
| `SYNC_INTERVAL_MINUTES` | `30` | Intervalo entre sincronizaciones en modo daemon |
| `DATA_DIR` | `~/.pideinfo-agent` | Directorio de datos del agente |
### Datos persistentes en `DATA_DIR`

| Fichero | Contenido |
|---|---|
| `preferences.json` | Token JWT, email y nombre del usuario |
| `cookies.json` | Timestamp de las cookies del portal principal (valores en keyring) |
| `cookies_ctbg.json` | Timestamp de las cookies del CTBG (valores en keyring) |
| `cookies_dehu.json` | Timestamp de las cookies de DEHú (valores en keyring) |
| `sync_state.json` | IDs de documentos ya sincronizados, pendientes por portal |
| `firefox-profile/` | Perfil persistente de Firefox: preferencias de certificado por origen |
| `downloads/` | Directorio temporal para documentos en tránsito |

---

## Empaquetado como aplicación de escritorio

El agente se distribuye como aplicación nativa mediante **PyInstaller** en modo `--onedir`.

### Decisiones de empaquetado

**Por qué PyInstaller y no Nuitka/Briefcase/cx_Freeze:**
Playwright lanza su propio proceso Node.js como subproceso. PyInstaller preserva la estructura de directorios necesaria para que `compute_driver_executable()` encuentre el binario `node` y `cli.js`. Nuitka compilaría Python a C pero rompería las rutas del subproceso de Playwright.

**Por qué `--onedir` y no `--onefile`:**
`--onefile` extrae todo a un directorio temporal al arrancar. Los paths de subprocesos (especialmente el driver de Playwright) se vuelven impredecibles. Con `--onedir` la estructura es estable y los paths son conocidos.

**Por qué Firefox no está empaquetado:**
El driver de Playwright (Node.js + `cli.js`) pesa ~124 MB y debe incluirse para que el agente funcione. Firefox pesa ~80 MB adicionales. En su lugar, Firefox se descarga en el primer arranque mediante `ensure_firefox()` y se guarda en un directorio persistente específico de la plataforma:

| Plataforma | Directorio de Firefox |
|---|---|
| macOS | `~/Library/Application Support/PideInfo Agent/playwright-browsers/` |
| Windows | `%LOCALAPPDATA%\PideInfo Agent\playwright-browsers\` |
| Linux | `~/.local/share/pideinfo-agent/playwright-browsers/` |

### Artefactos de distribución

| Plataforma | Formato | Tamaño aproximado |
|---|---|---|
| macOS arm64 | `.dmg` | ~130 MB |
| macOS x64 | `.dmg` | ~130 MB |
| Windows x64 | instalador NSIS `.exe` | ~125 MB |
| Linux x64 | `.AppImage` | ~125 MB |

La primera vez que se ejecuta, el agente descarga Firefox (~80 MB). Las siguientes veces arranca directamente.

### Construir manualmente

```bash
cd agent
pip install pyinstaller
pyinstaller build/pideinfo-agent.spec --distpath dist --workpath build/work
```

El artefacto queda en `dist/PideInfo Agent/` (o `dist/PideInfo Agent.app` en macOS).

---

## Actualización automática

El módulo `updater/github_updater.py` comprueba las GitHub Releases del repositorio buscando tags con el prefijo `agent-v` (para distinguirlos de los releases del backend principal).

### Flujo de actualización

```
Al arrancar en modo --tray:
  │
  ├─ check_for_update()
  │    └─ GET https://api.github.com/repos/Naroh091/vigia/releases?per_page=20
  │    └─ Filtra tags agent-v*, compara con __version__ usando packaging.version
  │    └─ Si hay versión mayor → guarda (version, download_url) en memoria
  │
  ├─ Si hay actualización:
  │    ├─ Añade "Actualizar a vX.Y.Z..." al menú del tray
  │    └─ Notificación de escritorio al usuario
  │
  └─ APScheduler repite la comprobación cada 6 horas

Al hacer clic en "Actualizar a vX.Y.Z...":
  │
  ├─ download_update() → descarga el asset de la plataforma a un fichero temporal
  │    macOS    → PideInfo-Agent-macos-arm64.dmg / macos-x64.dmg
  │    Windows  → PideInfo-Agent-windows-x64-setup.exe
  │    Linux    → PideInfo-Agent-linux-x64.AppImage
  │
  └─ apply_update()
       macOS   → open <fichero.dmg>  (el usuario arrastra la nueva .app)
       Windows → ejecuta el instalador .exe y sale
       Linux   → hace el AppImage ejecutable, muestra instrucciones y sale
```

---

## CI/CD

El workflow `.github/workflows/build-agent.yml` se activa con tags `agent-v*` (o manualmente).

### Matriz de build

| Runner | Plataforma | Artefacto |
|---|---|---|
| `macos-14` | Apple Silicon | `PideInfo-Agent-macos-arm64.dmg` |
| `macos-13` | Intel | `PideInfo-Agent-macos-x64.dmg` |
| `windows-latest` | x64 | `PideInfo-Agent-windows-x64-setup.exe` |
| `ubuntu-22.04` | x64 | `PideInfo-Agent-linux-x64.AppImage` |

### Pasos por plataforma

1. Instala Python 3.11 y dependencias del proyecto
2. Instala PyInstaller
3. Ejecuta `pyinstaller build/pideinfo-agent.spec`
4. **macOS**: crea DMG con `hdiutil`; firma con `codesign` si hay certificado disponible; notariza con `xcrun notarytool` si hay credenciales de Apple
5. **Windows**: empaqueta con NSIS usando `installer.nsi`
6. **Linux**: construye AppDir y empaqueta con `appimagetool`
7. Sube el artefacto como GitHub Release asset

### Crear un release

```bash
git tag agent-v1.0.0
git push origin agent-v1.0.0
```

El CI construye los cuatro binarios y crea automáticamente la GitHub Release con todos los assets adjuntos.

---

## Requisitos e instalación en desarrollo

**Python 3.11+** es obligatorio (el proyecto usa `match`, `tomllib` y otras características modernas).

```bash
cd agent

# Crear entorno virtual
python3.11 -m venv .venv
source .venv/bin/activate      # Windows: .venv\Scripts\activate

# Instalar dependencias
pip install -r requirements.txt

# Instalar el driver de Playwright y descargar Firefox
playwright install firefox

# Copiar y ajustar configuración
cp .env.example .env
# Editar .env con la URL de tu instancia de PideInfo

# Ejecutar una sincronización de prueba (sin enviar a PideInfo)
python main.py --dry-run

# Ejecutar como bandeja del sistema
python main.py --tray
```

### Dependencias principales

| Paquete | Versión | Uso |
|---|---|---|
| `playwright` | ≥1.40 | Autenticación headful con Cl@ve (Firefox, perfil persistente) |
| `httpx` | ≥0.27 | Peticiones HTTP asíncronas al portal y a PideInfo |
| `pydantic-settings` | ≥2.0 | Configuración tipada desde `.env` |
| `apscheduler` | ≥3.10,<4 | Sincronización periódica en modo daemon/tray |
| `keyring` | ≥25.0 | Almacenamiento seguro de cookies y passphrases |
| `pystray` | ≥0.19 | Icono en la barra del sistema |
| `Pillow` | ≥10.0 | Renderizado del icono del tray |
| `truststore` | ≥0.9 | Certificados raíz del sistema para CAs del gobierno |
| `beautifulsoup4` | ≥4.12 | Parseo HTML del CTBG (Wicket) |
| `rich` | ≥13.0 | Salida de consola con formato |
| `packaging` | ≥24.0 | Comparación semver para el auto-updater |
| `pyobjc-*` | ≥9.2 | Integración con macOS (solo en macOS) |
