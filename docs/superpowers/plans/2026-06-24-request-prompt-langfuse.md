# Request Prompt → Langfuse Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mover el contenido de dominio del prompt de generación de solicitudes a un prompt gestionado en Langfuse (`pideinfo-request-generate-request-chat`) con fallback bundled, manteniendo el protocolo de chat inline en PHP — espejo del flujo de reclamaciones.

**Architecture:** `RequestPromptComposer` deja de construir todo el prompt inline. El protocolo de chat (política de decisión + formato JSON + estado dinámico + bloques de canal) se queda inline; el contenido de dominio (rol, guía de canal, marco de resoluciones, reglas de redacción) se compila desde Langfuse vía `PromptStore::compile()` con `{{placeholders}}`, cayendo a `config/prompts/request/generate-request-chat.md` cuando Langfuse no está disponible. `compose()` pasa a devolver `CompiledPrompt` y el controlador propaga `promptRef`/`traceName` al turn para enlazar el trace.

**Tech Stack:** PHP 8 / Symfony, PHPUnit 11.5, Langfuse (vía `App\Prompt\PromptStore` + `BundledPromptLoader` + `LangfuseAdminClient`).

## Global Constraints

- Spec de referencia: `docs/superpowers/specs/2026-06-24-request-prompt-langfuse-design.md`.
- Nombre Langfuse: `pideinfo-request-generate-request-chat`. Convención de mapeo `BundledPromptLoader`: `pideinfo-{namespace}-{rest}` → `config/prompts/{namespace}/{rest}.md` (split en el PRIMER guion). Por tanto el archivo bundled DEBE ser `config/prompts/request/generate-request-chat.md`.
- Placeholders del template (formato Langfuse `{{var}}`): `organism`, `applicable_law_name`, `applicable_law_code`, `deadline`, `channel_block`, `similar_resolutions`.
- El output de solicitudes es **texto plano** (no HTML): REG = asunto/expone/solicita; Portal/correo = cuerpo único.
- La solicitud debe **citar siempre la ley aplicable** al amparo de la cual se solicita.
- Solo se ejecutan tests unitarios puros en este entorno (sin DB). Los tests de este plan no deben requerir base de datos.
- No tocar el flujo de reclamaciones ni `request/analyze-success-probability.md`.
- **No hacer `git commit` sin confirmación explícita de David** (regla de CLAUDE.md). Los pasos "Commit" de este plan quedan PREPARADOS pero requieren su OK antes de ejecutarse.
- La creación de la copia del prompt en Langfuse (Task 5) es una acción externa: requiere confirmación explícita antes de ejecutarse.

---

### Task 1: Bundled prompt template + carga vía PromptStore

Crea el archivo bundled del prompt de dominio y verifica que `PromptStore` (con Langfuse no configurado) lo compila sustituyendo todos los placeholders.

**Files:**
- Create: `config/prompts/request/generate-request-chat.md`
- Test: `tests/Prompt/RequestPromptTemplateTest.php`

**Interfaces:**
- Consumes: `App\Prompt\PromptStore::compile(string $name, array $vars): App\Prompt\CompiledPrompt`, `App\Prompt\BundledPromptLoader`, `App\Prompt\LangfuseAdminClient` (firmas existentes, ver `tests/Prompt/PromptStoreTest.php`).
- Produces: prompt bundled cargable por nombre `pideinfo-request-generate-request-chat`.

- [ ] **Step 1: Write the failing test**

Crear `tests/Prompt/RequestPromptTemplateTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Prompt;

use App\Prompt\BundledPromptLoader;
use App\Prompt\LangfuseAdminClient;
use App\Prompt\PromptStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

final class RequestPromptTemplateTest extends TestCase
{
    private function store(): PromptStore
    {
        // projectDir real del repo: tests/Prompt → raíz = dirname(__DIR__, 2)
        return new PromptStore(
            new BundledPromptLoader(\dirname(__DIR__, 2)),
            new LangfuseAdminClient(new MockHttpClient(), '', '', ''), // sin configurar → bundled
            new NullLogger(),
        );
    }

    public function testBundledTemplateCompilesWithAllPlaceholders(): void
    {
        $compiled = $this->store()->compile('pideinfo-request-generate-request-chat', [
            'organism' => 'Ayuntamiento de Madrid',
            'applicable_law_name' => 'Ley 19/2013',
            'applicable_law_code' => 'LTAIPBG',
            'deadline' => '1 mes (silencio negativo)',
            'channel_block' => '## Canal de prueba',
            'similar_resolutions' => 'Sin resoluciones.',
        ]);

        // Viene del bundled (Langfuse sin configurar) → sin versión.
        $this->assertNull($compiled->version);
        $this->assertSame('pideinfo-request-generate-request-chat', $compiled->name);
        // Todos los placeholders sustituidos: no quedan {{...}} residuales.
        $this->assertStringNotContainsString('{{', $compiled->text);
        // Valores inyectados presentes.
        $this->assertStringContainsString('Ayuntamiento de Madrid', $compiled->text);
        $this->assertStringContainsString('## Canal de prueba', $compiled->text);
        // Contenido de dominio esperado.
        $this->assertStringContainsString('solicitud de acceso a información pública', $compiled->text);
        $this->assertStringContainsString('Cita siempre la ley aplicable', $compiled->text);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Prompt/RequestPromptTemplateTest.php -v`
Expected: FAIL — `RuntimeException: Bundled prompt "pideinfo-request-generate-request-chat" not found at .../config/prompts/request/generate-request-chat.md`.

- [ ] **Step 3: Create the bundled template**

Crear `config/prompts/request/generate-request-chat.md` con exactamente:

```markdown
Eres un experto en el derecho de acceso a la información pública en España que ayuda a un ciudadano a redactar su **solicitud de acceso a información pública**. Hablas directamente con la persona que va a presentarla. Tu objetivo es llegar a un borrador útil, claro, conciso y bien fundamentado en la ley aplicable.

## Contexto de la solicitud

- **Organismo destinatario:** {{organism}}
- **Ley aplicable:** {{applicable_law_name}} ({{applicable_law_code}})
- **Plazo de respuesta:** {{deadline}}

{{channel_block}}

## Cómo redactar una buena solicitud

- **Concreción:** identifica con precisión la información que se pide. Una solicitud difusa es más fácil de inadmitir; una petición concreta y delimitada es mucho más difícil de denegar.
- **Cita siempre la ley aplicable:** ampara la petición EXPRESAMENTE en {{applicable_law_name}} ({{applicable_law_code}}). La solicitud debe dejar claro al amparo de qué norma se ejerce el derecho de acceso. No la conviertas, sin embargo, en un escrito jurídico extenso: a diferencia de una reclamación, aquí todavía no hay ningún argumento de la Administración que rebatir.
- **Evita causas de inadmisión:** formula la petición de modo que no parezca exigir reelaboración (art. 18.1.c LTAIBG o equivalente autonómico), ni información auxiliar o en curso de elaboración. Pide documentos o datos que la Administración ya posee.
- **Sin datos personales del solicitante en el cuerpo:** no incluyas nombre, DNI ni dirección; se añaden por separado.
- **Tono:** formal, cordial y directo. Español administrativo claro, sin tecnicismos innecesarios.

## Resoluciones similares

Estas resoluciones de consejos de transparencia tratan casos análogos. Úsalas SOLO para inspirarte sobre cómo enfocar y delimitar la petición — NO las cites en la solicitud ni copies su texto literalmente:

{{similar_resolutions}}

## Reglas de redacción

1. **Documento listo para enviar:** sin huecos ni placeholders ([nombre], [fecha], [completar]…).
2. **Texto plano:** NO uses HTML ni Markdown; respeta los límites de longitud de cada campo del canal.
3. **Cita la ley aplicable:** la solicitud debe invocar siempre {{applicable_law_name}} ({{applicable_law_code}}) como fundamento del derecho de acceso.
4. **Comillas rectas:** no uses comillas tipográficas.
5. **No inventes hechos:** si falta un dato concreto para delimitar la petición, pídeselo al usuario (acción `reply`) en lugar de suponerlo.
6. **Concisión:** reserva la extensión para delimitar con precisión qué se pide y por qué es información pública.
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php bin/phpunit tests/Prompt/RequestPromptTemplateTest.php -v`
Expected: PASS (1 test, varias assertions).

- [ ] **Step 5: Commit** *(requiere OK de David antes de ejecutar)*

```bash
git add config/prompts/request/generate-request-chat.md tests/Prompt/RequestPromptTemplateTest.php
git commit -m "feat: add bundled request-generation chat prompt for Langfuse"
```

---

### Task 2: `RequestPromptComposer` compila desde Langfuse y devuelve `CompiledPrompt`

Inyecta `PromptStore`, mueve el texto de dominio al template y cambia la firma de `compose()` a `CompiledPrompt`. El protocolo inline (`decisionPolicy`, `regChannelBlock`, `portalChannelBlock`) se mantiene.

**Files:**
- Modify: `src/Service/AI/Chat/Composer/RequestPromptComposer.php`
- Test: `tests/Service/AI/Chat/Composer/RequestPromptComposerTest.php`

**Interfaces:**
- Consumes: `App\Prompt\PromptStore::compile()` (Task 1), `App\Prompt\CompiledPrompt` (`->text`, `->name`, `->version`).
- Produces: `RequestPromptComposer::compose(AccessRequest $ar, array $similarResolutions): CompiledPrompt`. El `->text` contiene, en orden: política de decisión inline + texto del template + (opcional) bloque de preferencias de escritura. `->name === 'pideinfo-request-generate-request-chat'`.

- [ ] **Step 1: Write the failing test**

Crear `tests/Service/AI/Chat/Composer/RequestPromptComposerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\AI\Chat\Composer;

use App\Entity\AccessRequest;
use App\Entity\ApplicableLaw;
use App\Entity\PublicBody;
use App\Entity\User;
use App\Prompt\BundledPromptLoader;
use App\Prompt\CompiledPrompt;
use App\Prompt\LangfuseAdminClient;
use App\Prompt\PromptStore;
use App\Service\AI\Chat\Composer\RequestPromptComposer;
use App\Service\AI\ResolutionRetriever;
use App\Service\Submission\ApplicableLawResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

final class RequestPromptComposerTest extends TestCase
{
    private function composer(): RequestPromptComposer
    {
        $promptStore = new PromptStore(
            new BundledPromptLoader(\dirname(__DIR__, 5)), // tests/Service/AI/Chat/Composer → raíz
            new LangfuseAdminClient(new MockHttpClient(), '', '', ''),
            new NullLogger(),
        );

        // ResolutionRetriever no se invoca cuando la lista de resoluciones está vacía.
        $resolutionRetriever = $this->createStub(ResolutionRetriever::class);
        // ApplicableLawResolver::deadlineLabel se invoca porque la ley no es null.
        $lawResolver = $this->createStub(ApplicableLawResolver::class);
        $lawResolver->method('deadlineLabel')->willReturn('1 mes (silencio negativo)');

        return new RequestPromptComposer($resolutionRetriever, $lawResolver, $promptStore);
    }

    private function accessRequest(): AccessRequest
    {
        $law = $this->createStub(ApplicableLaw::class);
        $law->method('getName')->willReturn('Ley 19/2013');
        $law->method('getShortCode')->willReturn('LTAIPBG');

        $body = new PublicBody();
        $body->setName('Ayuntamiento de Madrid');

        $ar = new AccessRequest();
        $ar->setPublicBody($body);
        $ar->setUser(new User());
        $ar->setApplicableLaw($law);
        $ar->setTitle('Datos de contratos 2024');
        // Sin regDestination → canal Portal/correo. Sin descripción → no hay borrador.

        return $ar;
    }

    public function testComposeReturnsCompiledPromptLinkedToLangfuseName(): void
    {
        $compiled = $this->composer()->compose($this->accessRequest(), []);

        $this->assertInstanceOf(CompiledPrompt::class, $compiled);
        $this->assertSame('pideinfo-request-generate-request-chat', $compiled->name);
    }

    public function testComposeContainsInlinePolicyAndDomainTemplate(): void
    {
        $compiled = $this->composer()->compose($this->accessRequest(), []);

        // Protocolo inline (se queda en PHP).
        $this->assertStringContainsString('Política de decisión', $compiled->text);
        $this->assertStringContainsString('Formato de salida (OBLIGATORIO)', $compiled->text);
        // Contenido de dominio (desde el template bundled).
        $this->assertStringContainsString('Cómo redactar una buena solicitud', $compiled->text);
        $this->assertStringContainsString('Cita siempre la ley aplicable', $compiled->text);
        // Contexto sustituido.
        $this->assertStringContainsString('Ayuntamiento de Madrid', $compiled->text);
        // Canal Portal/correo seleccionado (no REG).
        $this->assertStringContainsString('Portal de Transparencia', $compiled->text);
        $this->assertStringNotContainsString('{{', $compiled->text);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Service/AI/Chat/Composer/RequestPromptComposerTest.php -v`
Expected: FAIL — `ArgumentCountError` / firma de constructor sin `PromptStore`, o `compose()` devolviendo `string` en lugar de `CompiledPrompt`.

- [ ] **Step 3: Modify `RequestPromptComposer`**

En `src/Service/AI/Chat/Composer/RequestPromptComposer.php`:

1) Añadir `use App\Prompt\CompiledPrompt;` y `use App\Prompt\PromptStore;` al bloque de imports.

2) Añadir `PromptStore` al constructor:

```php
    public function __construct(
        private readonly ResolutionRetriever $resolutionRetriever,
        private readonly ApplicableLawResolver $applicableLawResolver,
        private readonly PromptStore $promptStore,
    ) {
    }
```

3) Reemplazar el cuerpo de `compose()` (firma incluida). Antes construía `$sections` inline; ahora compila el template y antepone el protocolo:

```php
    /**
     * @param array<int, array<string, mixed>> $similarResolutions
     */
    public function compose(AccessRequest $ar, array $similarResolutions): CompiledPrompt
    {
        $isReg = $ar->getRegDestination() !== null;
        $law = $ar->getApplicableLaw();
        $deadline = $this->applicableLawResolver->deadlineLabel($law);

        $scaffolding = $this->promptStore->compile('pideinfo-request-generate-request-chat', [
            'organism' => $ar->getPublicBody()->getName(),
            'applicable_law_name' => $law->getName(),
            'applicable_law_code' => $law->getShortCode(),
            'deadline' => $deadline,
            'channel_block' => $isReg ? $this->regChannelBlock($ar) : $this->portalChannelBlock($ar),
            'similar_resolutions' => $this->formatResolutions($similarResolutions),
        ]);

        $fullText = $this->decisionPolicy($isReg, $ar) . "\n\n" . $scaffolding->text;

        $prefsBlock = WritingPreferencesFormatter::format($ar->getUser()->getWritingPreferences());
        if ($prefsBlock !== '') {
            $fullText .= "\n\n" . $prefsBlock;
        }

        return new CompiledPrompt(
            text: $fullText,
            name: $scaffolding->name,
            version: $scaffolding->version,
        );
    }
```

> Nota: `getApplicableLaw(): ApplicableLaw` es no-nullable, y `deadlineLabel(ApplicableLaw $law)` exige no-null; por eso se llama directo sin el `?->` previo (el código antiguo tenía un fallback null muerto que se elimina). `decisionPolicy()`, `regChannelBlock()`, `portalChannelBlock()` y `formatResolutions()` se mantienen SIN cambios.

- [ ] **Step 4: Run test to verify it passes**

Run: `php bin/phpunit tests/Service/AI/Chat/Composer/RequestPromptComposerTest.php -v`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit** *(requiere OK de David antes de ejecutar)*

```bash
git add src/Service/AI/Chat/Composer/RequestPromptComposer.php tests/Service/AI/Chat/Composer/RequestPromptComposerTest.php
git commit -m "refactor: source request-generation prompt from Langfuse via PromptStore"
```

---

### Task 3: Controlador propaga `promptRef`/`traceName` al turn

`compose()` ahora devuelve `CompiledPrompt`. Adaptar `AssistantChatController::request()` para usar `->text` y propagar la referencia del prompt y el nombre de trace, igual que `complaint()`.

**Files:**
- Modify: `src/Controller/AssistantChatController.php` (método `request()`, ~líneas 91-107)

**Interfaces:**
- Consumes: `RequestPromptComposer::compose(): CompiledPrompt` (Task 2); `AssistantChatRequest` (alias `AssistantChatTurn`) acepta `promptRef: ?CompiledPrompt`, `traceName: ?string`, `hasDraft: bool` (ya existentes en el constructor).
- Produces: turn de solicitudes con `promptRef`/`traceName` poblados.

- [ ] **Step 1: Modify `request()`**

En `src/Controller/AssistantChatController.php`, dentro de `request()`:

Reemplazar:

```php
        $similar = $this->loadSimilarResolutions($accessRequest);
        $systemPrompt = $this->requestPromptComposer->compose($accessRequest, $similar);
```

por:

```php
        $similar = $this->loadSimilarResolutions($accessRequest);
        $composedPrompt = $this->requestPromptComposer->compose($accessRequest, $similar);
```

Y reemplazar la construcción del turn:

```php
        $turn = new AssistantChatTurn(
            flow: 'request',
            entityId: $accessRequest->getId()->toRfc4122(),
            systemPrompt: $systemPrompt,
            userMessage: $userMessage,
            history: $history,
            attachments: $attachments,
            label: 'assistant.request',
        );
```

por:

```php
        $isReg = $accessRequest->getRegDestination() !== null;
        $hasDraft = $isReg
            ? (trim((string) $accessRequest->getExpone()) !== '' || trim((string) $accessRequest->getSolicita()) !== '')
            : trim((string) $accessRequest->getDescription()) !== '';

        $turn = new AssistantChatTurn(
            flow: 'request',
            entityId: $accessRequest->getId()->toRfc4122(),
            systemPrompt: $composedPrompt->text,
            userMessage: $userMessage,
            history: $history,
            attachments: $attachments,
            label: 'RequestGenerationStream',
            promptRef: $composedPrompt,
            traceName: 'RequestGenerationStream',
            hasDraft: $hasDraft,
        );
```

> El cálculo de `$hasDraft` replica la lógica de `decisionPolicy()` en el composer. `label` pasa de `'assistant.request'` a `'RequestGenerationStream'` para alinearse con el patrón de `complaint()` (`'ComplaintGenerationStream'`).

- [ ] **Step 2: Static check — lint + no quedan usos de `$systemPrompt`**

Run: `php -l src/Controller/AssistantChatController.php`
Expected: `No syntax errors detected`.

Run: `grep -n "systemPrompt\b" src/Controller/AssistantChatController.php`
Expected: Solo apariciones como nombre de parámetro `systemPrompt:` en las llamadas a `new AssistantChatTurn(...)`; NINGUNA referencia a una variable `$systemPrompt` ya inexistente en `request()`.

- [ ] **Step 3: Run the composer + prompt test suite to confirm no regressions**

Run: `php bin/phpunit tests/Prompt tests/Service/AI/Chat/Composer -v`
Expected: PASS (tests de Task 1 y Task 2 verdes).

- [ ] **Step 4: Commit** *(requiere OK de David antes de ejecutar)*

```bash
git add src/Controller/AssistantChatController.php
git commit -m "feat: link request-generation trace to Langfuse prompt ref"
```

---

### Task 4: Documentación

Reflejar que el prompt de solicitudes se gestiona en Langfuse con fallback bundled (requisito de CLAUDE.md: todo cambio se refleja en docs).

**Files:**
- Modify: `docs/request-workflow.md`
- Modify: `docs/architecture.md` (solo si tiene una sección de prompts/Langfuse; en caso contrario, omitir)

**Interfaces:** N/A (documentación).

- [ ] **Step 1: Localizar la sección de generación/prompt en los docs**

Run: `grep -rni "prompt\|langfuse\|RequestPromptComposer\|genera" docs/request-workflow.md docs/architecture.md`
Expected: identificar dónde se describe la generación del borrador de la solicitud.

- [ ] **Step 2: Añadir/actualizar la nota en `docs/request-workflow.md`**

Insertar (en la sección de generación del borrador) un párrafo equivalente a:

```markdown
El prompt de generación de la solicitud se gestiona en Langfuse bajo el nombre
`pideinfo-request-generate-request-chat`, con fallback bundled en
`config/prompts/request/generate-request-chat.md`. El protocolo de chat
(política de decisión `reply`/`generate`/`rewrite`, formato JSON de salida y los
bloques de canal REG/Portal) se mantiene inline en `RequestPromptComposer`; solo
el contenido de dominio (rol, guía de canal, marco de resoluciones y reglas de
redacción) procede del prompt gestionado. Es el mismo patrón que el flujo de
reclamaciones.
```

- [ ] **Step 3: Verify docs mention the new prompt**

Run: `grep -n "pideinfo-request-generate-request-chat" docs/request-workflow.md`
Expected: al menos una coincidencia.

- [ ] **Step 4: Commit** *(requiere OK de David antes de ejecutar)*

```bash
git add docs/request-workflow.md docs/architecture.md docs/superpowers/specs/2026-06-24-request-prompt-langfuse-design.md docs/superpowers/plans/2026-06-24-request-prompt-langfuse.md
git commit -m "docs: document Langfuse-managed request-generation prompt"
```

---

### Task 5: Crear la copia del prompt en Langfuse *(acción externa — GATED)*

Subir el contenido del template a Langfuse para que la versión gestionada exista (y el trace registre `version`). Hasta entonces el sistema funciona vía fallback bundled.

**Files:** N/A (acción externa vía MCP de Langfuse).

**Interfaces:** `mcp__langfuse__createTextPrompt` (nombre `pideinfo-request-generate-request-chat`, label `production`), o creación manual en la UI de Langfuse.

- [ ] **Step 1: PARAR y confirmar con David**

No ejecutar sin OK explícito. Confirmar: (a) que se crea en Langfuse, (b) el label (`production` por defecto), (c) si el contenido a subir es exactamente el de `config/prompts/request/generate-request-chat.md`.

- [ ] **Step 2: Crear el prompt en Langfuse**

Usar `mcp__langfuse__createTextPrompt` con el contenido del archivo bundled y label `production`. (Verificar antes el `LANGFUSE_PROMPT_LABEL` efectivo del entorno destino.)

- [ ] **Step 3: Verificar resolución desde Langfuse**

Comprobar que, con Langfuse configurado, `PromptStore::compile('pideinfo-request-generate-request-chat')` devuelve `version` no-null (vía un trace real de generación de solicitud o un test de integración manual). Si Langfuse no es accesible desde este entorno, dejar constancia y delegar la verificación al entorno donde sí lo sea.

---

## Self-Review

**1. Spec coverage:**
- Decisión 1 (protocolo inline, dominio a Langfuse) → Task 2 (compose antepone `decisionPolicy()` al template). ✔
- Decisión 2 (una plantilla, `{{channel_block}}`) → Task 1 (template) + Task 2 (PHP inyecta el bloque de canal). ✔
- Nombre Langfuse + fallback bundled → Task 1. ✔
- `compose()` → `CompiledPrompt` → Task 2. ✔
- Controlador propaga `promptRef`/`traceName` → Task 3. ✔
- Cita obligatoria de la ley aplicable → presente en el template (bullet + regla 3) y testeada en Task 1/Task 2. ✔
- Docs → Task 4. ✔
- Creación en Langfuse (gated) → Task 5. ✔

**2. Placeholder scan:** Todos los pasos de código incluyen el código real; sin "TBD"/"TODO"/"add error handling". ✔

**3. Type consistency:** `compose()` devuelve `CompiledPrompt` en Task 2 y se consume como `->text`/`promptRef` en Task 3. `PromptStore::compile()` devuelve `CompiledPrompt` (`->text`/`->name`/`->version`), usado consistentemente en Tasks 1-2. Nombre del prompt idéntico en todas las tareas: `pideinfo-request-generate-request-chat`. ✔

> Nota de verificación de rutas: los tests calculan la raíz del repo con `dirname(__DIR__, N)` — `2` para `tests/Prompt/`, `5` para `tests/Service/AI/Chat/Composer/`. Si al ejecutar Step 2 de cada tarea el error es "Bundled prompt not found" por ruta incorrecta, ajustar `N` hasta que apunte a la raíz del repo (donde vive `config/`).
