# Anon Input Moderation — Conversation Context Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Feed the anonymous input moderator lightweight conversation context (`hasDraft` + last assistant turn) so follow-up questions about an in-progress draft stop being blocked as `off_scope`.

**Architecture:** A new `ModerationContext` value object renders a `{{context}}` prompt block. `AnonymousModerationGuard::moderate()` gains an optional context parameter it substitutes into the input prompt. `AssistantChatController::streamEvents()` builds the context from the `AssistantChatRequest` (`$turn`) it already holds and passes it on the input call only. Output moderation is untouched.

**Tech Stack:** PHP 8 / Symfony, PHPUnit, self-hosted LLM via `LlmClient`, Langfuse-managed prompts with bundled `config/prompts/` fallback.

## Global Constraints

- **Commits require David's explicit OK** (CLAUDE.md). Each task lists a commit command, but do NOT run `git commit` until David confirms. Stage with `git add`, show the diff, and wait.
- **Any updates must be reflected in the docs** (CLAUDE.md) — Task 4 covers `docs/anonymous-drafting.md`.
- **Langfuse divergence:** prod prefers the Langfuse `production` copy of `pideinfo-moderation-input` over the bundled file. The bundled edit here fixes local/test/fallback only. The new `{{context}}` placeholder + guidance must ALSO be pushed to Langfuse for prod to render context — flag to David at the end; do not push without his OK.
- Output moderation (`ModerationStage::Output`) and the PERMITE/BLOQUEA lists must NOT change.
- Run tests with `bin/phpunit`. Compare any failures against the known baseline (8 preexisting failures on master) before blaming this change.

---

### Task 1: `ModerationContext` value object

**Files:**
- Create: `src/Service/AI/Moderation/ModerationContext.php`
- Test: `tests/Service/AI/Moderation/ModerationContextTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `final readonly class ModerationContext` with constructor `__construct(public bool $hasDraft, public ?string $lastAssistantMessage = null)` and method `toPromptBlock(): string`. Returns `''` when `!hasDraft` and the last message is empty/null; otherwise a multi-line block. Truncates the last assistant message to 600 chars and collapses whitespace internally.

- [ ] **Step 1: Write the failing test**

Create `tests/Service/AI/Moderation/ModerationContextTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\AI\Moderation;

use App\Service\AI\Moderation\ModerationContext;
use PHPUnit\Framework\TestCase;

class ModerationContextTest extends TestCase
{
    public function testEmptyWhenNoDraftAndNoAssistantMessage(): void
    {
        $this->assertSame('', (new ModerationContext(false, null))->toPromptBlock());
        $this->assertSame('', (new ModerationContext(false, '   '))->toPromptBlock());
    }

    public function testRendersDraftFlagAndLastAssistantMessage(): void
    {
        $block = (new ModerationContext(true, "He redactado   una\nsolicitud"))->toPromptBlock();

        $this->assertStringContainsString('Borrador en curso: sí', $block);
        // Whitespace (incl. newlines) collapsed to single spaces.
        $this->assertStringContainsString('Último mensaje del asistente: "He redactado una solicitud"', $block);
    }

    public function testRendersFlagAloneWhenDraftButNoMessage(): void
    {
        $block = (new ModerationContext(true, null))->toPromptBlock();

        $this->assertStringContainsString('Borrador en curso: sí', $block);
        $this->assertStringNotContainsString('Último mensaje', $block);
    }

    public function testTruncatesLongAssistantMessage(): void
    {
        $long = str_repeat('a', 900);
        $block = (new ModerationContext(true, $long))->toPromptBlock();

        $this->assertStringContainsString('…', $block);
        $this->assertLessThan(900, mb_strlen($block));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `bin/phpunit tests/Service/AI/Moderation/ModerationContextTest.php`
Expected: FAIL — `Class "App\Service\AI\Moderation\ModerationContext" not found`.

- [ ] **Step 3: Write minimal implementation**

Create `src/Service/AI/Moderation/ModerationContext.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\AI\Moderation;

/**
 * Lightweight conversation context handed to the anonymous INPUT moderation pass
 * so a follow-up on an in-progress draft is judged as a refinement rather than as
 * a stranger's opening message. Renders the `{{context}}` block for
 * `config/prompts/moderation/input.md`.
 *
 * When there is neither a draft nor a prior assistant turn, {@see toPromptBlock()}
 * returns an empty string so the opening-message path stays byte-identical to the
 * pre-context behaviour.
 */
final readonly class ModerationContext
{
    /** Max characters of the last assistant turn included in the prompt block. */
    private const MAX_LAST_ASSISTANT_CHARS = 600;

    public function __construct(
        public bool $hasDraft,
        public ?string $lastAssistantMessage = null,
    ) {
    }

    public function toPromptBlock(): string
    {
        $last = $this->lastAssistantMessage !== null ? trim($this->lastAssistantMessage) : '';

        if (!$this->hasDraft && $last === '') {
            return '';
        }

        $lines = ['- Borrador en curso: ' . ($this->hasDraft ? 'sí' : 'no')];

        if ($last !== '') {
            $last = (string) preg_replace('/\s+/', ' ', $last);
            if (mb_strlen($last) > self::MAX_LAST_ASSISTANT_CHARS) {
                $last = mb_substr($last, 0, self::MAX_LAST_ASSISTANT_CHARS) . '…';
            }
            $lines[] = '- Último mensaje del asistente: "' . $last . '"';
        }

        return implode("\n", $lines);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `bin/phpunit tests/Service/AI/Moderation/ModerationContextTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit** (await David's OK before `git commit`)

```bash
git add src/Service/AI/Moderation/ModerationContext.php tests/Service/AI/Moderation/ModerationContextTest.php
git commit -m "feat(moderation): add ModerationContext value object for input screening"
```

---

### Task 2: Guard accepts context + input prompt renders it

**Files:**
- Modify: `src/Service/AI/Moderation/AnonymousModerationGuard.php` (method `moderate`, ~lines 53-89)
- Modify: `config/prompts/moderation/input.md`
- Test: `tests/Service/AI/Moderation/AnonymousModerationGuardTest.php`

**Interfaces:**
- Consumes: `ModerationContext` (Task 1); `PromptStore::compile(string, array)`; `ModerationStage`.
- Produces: new signature `moderate(string $text, ModerationStage $stage, ?ModerationContext $context = null): ModerationVerdict`. The compiled system prompt contains the context block when a context is given, and no `{{context}}` literal ever survives.

- [ ] **Step 1: Write the failing tests**

Add these two methods to `tests/Service/AI/Moderation/AnonymousModerationGuardTest.php` (the file already imports `ChatRequest`, `ModerationStage`; add `use App\Service\AI\Moderation\ModerationContext;` to the imports):

```php
    public function testInputContextBlockAppearsInCompiledPrompt(): void
    {
        $captured = null;
        $llm = $this->createMock(LlmClient::class);
        $llm->method('chatJson')->willReturnCallback(function (ChatRequest $r) use (&$captured) {
            $captured = $r;

            return ['allowed' => true, 'category' => 'clean'];
        });

        $context = new ModerationContext(true, 'He redactado una solicitud sobre los expedientes 12/2024.');
        $this->guard($llm)->moderate(
            '¿Qué dice la LCSP que debe contener el expediente?',
            ModerationStage::Input,
            $context,
        );

        $this->assertNotNull($captured);
        $this->assertStringContainsString('Borrador en curso: sí', $captured->systemPrompt);
        $this->assertStringContainsString('expedientes 12/2024', $captured->systemPrompt);
        $this->assertStringNotContainsString('{{context}}', $captured->systemPrompt);
    }

    public function testNullContextLeavesNoContextBlock(): void
    {
        $captured = null;
        $llm = $this->createMock(LlmClient::class);
        $llm->method('chatJson')->willReturnCallback(function (ChatRequest $r) use (&$captured) {
            $captured = $r;

            return ['allowed' => true, 'category' => 'clean'];
        });

        $this->guard($llm)->moderate('hola', ModerationStage::Input);

        $this->assertNotNull($captured);
        $this->assertStringNotContainsString('Borrador en curso', $captured->systemPrompt);
        $this->assertStringNotContainsString('{{context}}', $captured->systemPrompt);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `bin/phpunit tests/Service/AI/Moderation/AnonymousModerationGuardTest.php --filter Context`
Expected: FAIL — `testInputContextBlockAppearsInCompiledPrompt` fails because `{{context}}` is still literal in the prompt (and `moderate` ignores the 3rd arg). `testNullContextLeavesNoContextBlock` may fail on the surviving `{{context}}` literal.

- [ ] **Step 3a: Add the `{{context}}` section to the input prompt**

In `config/prompts/moderation/input.md`, insert a new section immediately **before** the line `## PERMITE (allowed = true, category = "clean")`:

```markdown
## CONTEXTO DE LA CONVERSACIÓN
{{context}}

Si ya hay un borrador en curso, el mensaje casi siempre es un seguimiento para afinar esa solicitud (preguntas jurídicas —LCSP, LTBG, plazos, qué debe contener un expediente—, cambios de tono o estructura). Trátalo DENTRO de ámbito salvo señal clara de abuso (jailbreak, contenido dañino, PII de terceros).

```

(Leave PERMITE/BLOQUEA and the rest of the file unchanged.)

- [ ] **Step 3b: Thread the context through the guard**

In `src/Service/AI/Moderation/AnonymousModerationGuard.php`, change the `moderate` signature and the `compile` call:

```php
    public function moderate(string $text, ModerationStage $stage, ?ModerationContext $context = null): ModerationVerdict
    {
        $text = trim($text);
        if ($text === '') {
            return ModerationVerdict::allow();
        }

        if (mb_strlen($text) > self::MAX_INPUT_CHARS) {
            $text = mb_substr($text, 0, self::MAX_INPUT_CHARS) . "\n\n[…texto truncado]";
        }

        $prompt = $this->promptStore->compile($stage->promptName(), [
            'text'    => $text,
            'context' => $context?->toPromptBlock() ?? '',
        ]);
```

(The rest of the method body is unchanged. No new `use` needed — `ModerationContext` is in the same namespace.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `bin/phpunit tests/Service/AI/Moderation/AnonymousModerationGuardTest.php`
Expected: PASS (all existing tests + the 2 new ones). The existing `testCompilesTheStageSpecificPrompt` still passes because the output prompt has no `{{context}}` placeholder and the extra `compile` var is a harmless no-op there.

- [ ] **Step 5: Commit** (await David's OK before `git commit`)

```bash
git add src/Service/AI/Moderation/AnonymousModerationGuard.php config/prompts/moderation/input.md tests/Service/AI/Moderation/AnonymousModerationGuardTest.php
git commit -m "feat(moderation): render conversation context in the anon input screen"
```

---

### Task 3: Wire context from the turn at the call site

**Files:**
- Modify: `src/Controller/AssistantChatController.php` (imports; `streamEvents` input branch ~line 356-357; new private helper near `draftText`, ~line 658)
- Test: `tests/Controller/AssistantChatModerationTest.php`

**Interfaces:**
- Consumes: `AssistantChatRequest` public props `hasDraft` (bool) and `history` (`ChatMessage[]`); `ModerationContext` (Task 1); `AnonymousModerationGuard::moderate(string, ModerationStage, ?ModerationContext)` (Task 2).
- Produces: the input moderation call now passes a `ModerationContext` reflecting `$turn`. New private method `lastAssistantMessage(array $history): ?string`.

- [ ] **Step 1: Write the failing test**

In `tests/Controller/AssistantChatModerationTest.php`, add a helper for a turn that carries a draft + history, and a test asserting the guard receives a matching context. Add `use App\DTO\ChatMessage;` and `use App\Service\AI\Moderation\ModerationContext;` to the imports, then:

```php
    private function turnWithDraft(): AssistantChatTurn
    {
        return new AssistantChatTurn(
            flow: 'request',
            entityId: 'e',
            systemPrompt: 's',
            userMessage: 'u',
            history: [
                new ChatMessage('user', 'Quiero pedir unos expedientes'),
                new ChatMessage('assistant', 'He redactado una solicitud sobre los expedientes 12/2024.'),
            ],
            attachments: [],
            label: 'test',
            hasDraft: true,
        );
    }

    public function testInputModerationReceivesConversationContext(): void
    {
        $seen = null;
        $guard = $this->createMock(AnonymousModerationGuard::class);
        $guard->method('moderate')->willReturnCallback(
            function (string $t, ModerationStage $s, ?ModerationContext $ctx = null) use (&$seen) {
                if ($s === ModerationStage::Input) {
                    $seen = $ctx;
                }

                return ModerationVerdict::allow();
            },
        );

        $streamer = $this->streamerYielding([
            ['decision', ['action' => 'reply', 'draft' => null, 'plan' => []]],
        ]);

        $c = $this->makeController($guard, $streamer);
        $ar = new AccessRequest();

        $this->events($c, $this->turnWithDraft(), '¿Qué dice la LCSP sobre el expediente?', function (string $a, ?array $d, string $r) {
            return null;
        }, $ar);

        $this->assertInstanceOf(ModerationContext::class, $seen);
        $this->assertTrue($seen->hasDraft);
        $this->assertSame('He redactado una solicitud sobre los expedientes 12/2024.', $seen->lastAssistantMessage);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `bin/phpunit tests/Controller/AssistantChatModerationTest.php --filter testInputModerationReceivesConversationContext`
Expected: FAIL — `$seen` is `null` (the controller still calls `moderate` with 2 args, so the `?ModerationContext $ctx = null` param stays null).

- [ ] **Step 3a: Add the import**

In `src/Controller/AssistantChatController.php`, next to `use App\Service\AI\Moderation\AnonymousModerationGuard;`, add:

```php
use App\Service\AI\Moderation\ModerationContext;
```

- [ ] **Step 3b: Build and pass the context**

In `streamEvents`, replace the input moderation call:

```php
        if ($anonymous && $accessRequest !== null) {
            $context = new ModerationContext(
                hasDraft: $turn->hasDraft,
                lastAssistantMessage: $this->lastAssistantMessage($turn->history),
            );
            $verdict = $this->moderationGuard->moderate($userMessage, ModerationStage::Input, $context);
            if (!$verdict->allowed) {
```

(Everything inside the `if (!$verdict->allowed)` block is unchanged.)

- [ ] **Step 3c: Add the private helper**

Near `draftText()` (~line 658), add:

```php
    /**
     * Most recent assistant turn in the history (role !== 'user'), or null if the
     * visitor has not received any assistant turn yet. Fed to input moderation so a
     * follow-up is judged against the request in progress, not in a vacuum.
     *
     * @param \App\DTO\ChatMessage[] $history
     */
    private function lastAssistantMessage(array $history): ?string
    {
        for ($i = \count($history) - 1; $i >= 0; $i--) {
            if ($history[$i]->role !== 'user') {
                return $history[$i]->content;
            }
        }

        return null;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `bin/phpunit tests/Controller/AssistantChatModerationTest.php`
Expected: PASS — the new test plus all existing moderation tests (the pre-existing `moderate` mock callbacks declare only 2 params but are called with 3; PHP ignores the extra positional arg, so they keep working).

- [ ] **Step 5: Commit** (await David's OK before `git commit`)

```bash
git add src/Controller/AssistantChatController.php tests/Controller/AssistantChatModerationTest.php
git commit -m "feat(moderation): pass turn context to the anon input screen"
```

---

### Task 4: Docs

**Files:**
- Modify: `docs/anonymous-drafting.md` (Entrada bullet, ~lines 90-92)

**Interfaces:** none (documentation only).

- [ ] **Step 1: Update the Entrada bullet**

In `docs/anonymous-drafting.md`, replace the **Entrada** bullet:

```markdown
  - **Entrada**: el mensaje del visitante se modera **antes** de arrancar el
    agente (`AgentChatOrchestrator::stream`), así un mensaje fuera de ámbito o un
    jailbreak no gasta el turno caro. Recibe además un **contexto ligero de
    conversación** (`ModerationContext`: `hasDraft` + último turno del asistente,
    truncado) para que un seguimiento sobre un borrador en curso —p. ej. una
    pregunta jurídica sobre la información pedida— no se juzgue en el vacío y se
    bloquee como `off_scope`. Sin borrador ni turno previo el bloque va vacío y el
    mensaje de apertura se evalúa igual que antes.
```

- [ ] **Step 2: Verify the doc renders sensibly**

Run: `grep -n "ModerationContext" docs/anonymous-drafting.md`
Expected: one match in the Entrada bullet.

- [ ] **Step 3: Commit** (await David's OK before `git commit`)

```bash
git add docs/anonymous-drafting.md
git commit -m "docs(anon): note input moderation now gets conversation context"
```

---

## Final verification

- [ ] Run the full moderation-related suite:
  `bin/phpunit tests/Service/AI/Moderation tests/Controller/AssistantChatModerationTest.php`
  Expected: all green.
- [ ] Run the broader suite and compare against the known baseline (8 preexisting failures on master): `bin/phpunit`. No NEW failures attributable to this change.
- [ ] Flag to David: push the updated `pideinfo-moderation-input` (with `{{context}}` + the guidance line) to Langfuse `production`, or prod keeps serving the old placeholder-less prompt. Do not push without his OK.
