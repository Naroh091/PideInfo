# Agent-present progress modal + CMD+K case fix — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the agent-submission modal show live task progress (and a done/error state) across all three submission flows, and fix the case-sensitive ⌘K search.

**Architecture:** A single `Alpine.store('agentPresent')` is the one source of truth for in-flight/polling state; one shared modal partial renders it; each of the three entry points (complaint draft pages, solicitudes/show, realizar bulk dispatch) feeds the store and is unified onto it. The store is callable from Alpine *and* plain JS, so the realizar Stimulus controller drives the same modal. A one-line Doctrine change makes ⌘K search case-insensitive.

**Tech Stack:** Symfony 7 (PHP 8.2), Twig, Stimulus (AssetMapper), Alpine.js 3 (CDN), Tailwind, Doctrine/PostgreSQL, Lucide icons.

## Global Constraints

- **No commits without David's explicit OK** (CLAUDE.md). Every "Commit" step below is GATED: prepare the commit message and `git add`, but only run `git commit` after David confirms. Never `git push`.
- All work happens in the worktree `feat/agent-present-modal-progress` (already created from `master`).
- Alpine is loaded from CDN with `defer` in `templates/layouts/app.html.twig`; register the store inside a `document.addEventListener('alpine:init', …)` handler placed **before** the Alpine `<script>` tag.
- After any change to `assets/styles/app.css` or markup that introduces Tailwind classes, run `php bin/console tailwind:build` (project memory: cache:clear won't refresh styles). The modal's own animation uses a plain `<style>` block, not Tailwind utilities, but new utility classes in the partial require a rebuild.
- Status label mapping is fixed (do not improvise copy):
  - `pending` → `Esperando que el agente comience la tarea…`
  - `claimed` → `Agente conectado, preparando…`
  - `in_progress` → `Realizando presentación…`
  - `done` → confirmation (no per-row sub-label needed beyond the check)
  - `failed` → error block + the task's real `errorMessage`
  - `uncertain` → amber "revisa manualmente en la sede" note
- AgentTask terminal statuses: `done`, `failed`, `uncertain`.
- Doctrine text search convention in this repo is `LOWER(col) LIKE LOWER(:param)` (see `ResolutionRepository`).
- Test reality: no JS test runner; repository tests are `KernelTestCase` (need a DB; baseline-failing in this sandbox but valid in CI). Use TDD where a test can be written; otherwise use `tailwind:build` + the manual QA checklist in Task 8.

## File structure

| File | Responsibility | Action |
|------|----------------|--------|
| `src/Repository/AccessRequestRepository.php` | `searchForLinking()` — case-insensitive text predicate | Modify |
| `tests/Repository/AccessRequestRepositoryTest.php` | Integration test for case-insensitive search | Create |
| `templates/layouts/app.html.twig` | Register `Alpine.store('agentPresent')` before Alpine CDN | Modify |
| `templates/_partials/_agent_present_modal.html.twig` | Shared animated modal bound to the store | Create (moved from complaint/) |
| `templates/complaint/_present_modal.html.twig` | Old complaint-only modal | Delete |
| `templates/complaint/draft.html.twig` | Flow A host: drive store from `presentViaAgent` | Modify |
| `templates/complaint/redactar.html.twig` | Flow A host | Modify |
| `templates/complaint/interactive.html.twig` | Flow A host | Modify |
| `templates/solicitudes/show.html.twig` | Flow B: drop pill/Stimulus, use modal | Modify |
| `assets/controllers/agent_present_controller.js` | Old Stimulus pill flow | Delete |
| `src/Controller/AccessRequestController.php` | `dispatchBatch()` returns `tasks[]` | Modify |
| `assets/controllers/realizar_canvas_controller.js` | Flow C: drive store instead of redirect | Modify |
| `templates/solicitudes/realizar/draft.html.twig` | Flow C: include modal partial | Modify |
| `docs/complaint-workflow.md`, `docs/request-workflow.md` | Document the unified progress modal | Modify |

---

## Task 1: CMD+K case-insensitive search

**Files:**
- Modify: `src/Repository/AccessRequestRepository.php` (method `searchForLinking`, the `andWhere(...LIKE :q...)` predicate, ~line 489, and the `publicBody` filter a few lines below)
- Test: `tests/Repository/AccessRequestRepositoryTest.php` (create)

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces: nothing other tasks depend on. Self-contained.

- [ ] **Step 1: Write the failing test**

Create `tests/Repository/AccessRequestRepositoryTest.php`. Follow the existing `AgentTaskRepositoryTest` style (`KernelTestCase`, boot kernel, get EM, create a `User` + `PublicBody` + `AccessRequest`, assert search matches regardless of case).

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\AccessRequest;
use App\Entity\PublicBody;
use App\Entity\User;
use App\Repository\AccessRequestRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class AccessRequestRepositoryTest extends KernelTestCase
{
    public function testSearchForLinkingIsCaseInsensitive(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        /** @var AccessRequestRepository $repo */
        $repo = $em->getRepository(AccessRequest::class);

        $user = new User();
        $user->setEmail('cmdk-test+'.bin2hex(random_bytes(4)).'@example.com');
        $user->setPassword('x');
        $user->setFirstName('Test');
        $user->setLastName('User');
        $em->persist($user);

        $body = new PublicBody();
        $body->setName('Ayuntamiento de Madrid');
        // set any other non-nullable PublicBody fields the entity requires
        $em->persist($body);

        $ar = new AccessRequest();
        $ar->setUser($user);
        $ar->setPublicBody($body);
        $ar->setTitle('Contratos de MADRID Central');
        $ar->setDescription('Información sobre contratos');
        // set any other non-nullable AccessRequest fields the entity requires
        $em->persist($ar);
        $em->flush();

        $lower = $repo->searchForLinking($user, 'madrid');
        $upper = $repo->searchForLinking($user, 'MADRID');

        self::assertNotEmpty($lower, 'lowercase query must match');
        self::assertCount(\count($lower), $upper, 'upper/lower must return the same count');
    }
}
```

Note: inspect `AccessRequest`/`PublicBody` constructors for required fields and the exact `searchForLinking` signature (first arg may be `User $user`); adjust setters to satisfy non-nullable columns.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php bin/phpunit tests/Repository/AccessRequestRepositoryTest.php`
Expected: the `MADRID` (uppercase) assertion fails (0 results) on PostgreSQL — proving the bug. (If the sandbox has no DB, this errors on connection; in that case record it as a CI-only test and verify the fix by reading the generated DQL in Step 4 + the manual QA in Task 8.)

- [ ] **Step 3: Make the predicate case-insensitive**

In `src/Repository/AccessRequestRepository.php::searchForLinking`, replace the bare-LIKE predicate:

```php
// BEFORE
$qb->andWhere('ar.title LIKE :q OR ar.externalId LIKE :q OR ar.description LIKE :q OR pb.name LIKE :q')
   ->setParameter('q', '%' . $query . '%');
```

```php
// AFTER
$qb->andWhere('LOWER(ar.title) LIKE LOWER(:q) OR LOWER(ar.externalId) LIKE LOWER(:q) '
            . 'OR LOWER(ar.description) LIKE LOWER(:q) OR LOWER(pb.name) LIKE LOWER(:q)')
   ->setParameter('q', '%' . $query . '%');
```

And the `publicBody` filter in the same method (apply the same convention):

```php
// BEFORE
->andWhere('pb.name LIKE :publicBody')
->setParameter('publicBody', '%' . $publicBody . '%');
```

```php
// AFTER
->andWhere('LOWER(pb.name) LIKE LOWER(:publicBody)')
->setParameter('publicBody', '%' . $publicBody . '%');
```

(Match the exact surrounding code when editing — locate by the `LIKE :q` / `LIKE :publicBody` strings.)

- [ ] **Step 4: Run the test to verify it passes**

Run: `php bin/phpunit tests/Repository/AccessRequestRepositoryTest.php`
Expected: PASS (upper and lower return equal counts). If no DB in sandbox, instead confirm by reading the method that all four columns and both params are wrapped in `LOWER(...)`.

- [ ] **Step 5: Commit (GATED — ask David first)**

```bash
git add src/Repository/AccessRequestRepository.php tests/Repository/AccessRequestRepositoryTest.php
git commit -m "fix: make ⌘K solicitud search case-insensitive

PostgreSQL LIKE is case-sensitive; wrap columns/params in LOWER() to match
the ResolutionRepository convention.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: `Alpine.store('agentPresent')`

**Files:**
- Modify: `templates/layouts/app.html.twig` (near the Alpine CDN `<script>`, ~line 232-233)

**Interfaces:**
- Consumes: nothing.
- Produces: `Alpine.store('agentPresent')` with this public surface, relied on by Tasks 3–6:
  - `phase: 'idle'|'enqueuing'|'tracking'|'done'|'error'`
  - `tasks: Array<{id,statusUrl,schemeUrl,bodyName,status,errorMessage,label,kind}>`
  - `entityLabel: string`, `globalError: string|null`, `doneHref: string|null`
  - `open(entityLabel: string): void`
  - `track(tasks: Array<{id,statusUrl,schemeUrl?,bodyName?}>, opts?: {entityLabel?:string, doneHref?:string}): void`
  - `fail(message: string): void`
  - `close(): void`
  - `statusLabel(status: string): {text:string, kind:string}`
  - getters: `busy` (phase is enqueuing|tracking), `successCount`, `total`, `anyError`

- [ ] **Step 1: Add the store registration before the Alpine CDN tag**

In `templates/layouts/app.html.twig`, immediately **before** the line
`<script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>`, insert:

```html
{# Shared agent-present modal store — single source of truth for the
   "presentar con el agente" progress modal (complaints + solicitudes). #}
<script>
document.addEventListener('alpine:init', () => {
    Alpine.store('agentPresent', {
        phase: 'idle',            // idle | enqueuing | tracking | done | error
        tasks: [],
        entityLabel: 'tarea',
        globalError: null,
        doneHref: null,
        _timer: null,

        get busy() { return this.phase === 'enqueuing' || this.phase === 'tracking'; },
        get total() { return this.tasks.length; },
        get successCount() { return this.tasks.filter(t => t.status === 'done').length; },
        get anyError() { return this.tasks.some(t => t.status === 'failed' || t.status === 'uncertain'); },

        statusLabel(status) {
            switch (status) {
                case 'pending':     return { text: 'Esperando que el agente comience la tarea…', kind: 'waiting' };
                case 'claimed':     return { text: 'Agente conectado, preparando…',             kind: 'working' };
                case 'in_progress': return { text: 'Realizando presentación…',                  kind: 'working' };
                case 'done':        return { text: 'Presentación realizada.',                    kind: 'done' };
                case 'failed':      return { text: 'Falló la presentación.',                     kind: 'failed' };
                case 'uncertain':   return { text: 'Resultado incierto: revísalo en la sede.',   kind: 'uncertain' };
                default:            return { text: status || '…',                               kind: 'waiting' };
            }
        },

        open(entityLabel) {
            this._stop();
            this.entityLabel = entityLabel || 'tarea';
            this.tasks = [];
            this.globalError = null;
            this.doneHref = null;
            this.phase = 'enqueuing';
        },

        track(tasks, opts) {
            opts = opts || {};
            if (opts.entityLabel) this.entityLabel = opts.entityLabel;
            this.doneHref = opts.doneHref || null;
            this.globalError = null;
            this.tasks = (tasks || []).map(t => {
                const lbl = this.statusLabel('pending');
                return {
                    id: t.id, statusUrl: t.statusUrl,
                    schemeUrl: t.schemeUrl || null,
                    bodyName: t.bodyName || null,
                    status: 'pending', errorMessage: null,
                    label: lbl.text, kind: lbl.kind,
                };
            });
            if (this.tasks.length === 0) { this.fail('No se creó ninguna tarea.'); return; }
            this.phase = 'tracking';
            this._tick();
        },

        fail(message) {
            this._stop();
            this.globalError = message || 'No se pudo completar la operación.';
            this.phase = 'error';
        },

        async _tick() {
            const pending = this.tasks.filter(t => !this._isTerminal(t.status));
            await Promise.all(pending.map(async (t) => {
                try {
                    const r = await fetch(t.statusUrl, { headers: { 'Accept': 'application/json' } });
                    if (!r.ok) return;
                    const data = await r.json();
                    t.status = data.status || t.status;
                    t.errorMessage = data.errorMessage || null;
                    const lbl = this.statusLabel(t.status);
                    t.label = lbl.text; t.kind = lbl.kind;
                } catch (_) { /* transient — keep polling */ }
            }));

            if (this.tasks.every(t => this._isTerminal(t.status))) {
                this._stop();
                this.phase = (this.successCount > 0) ? 'done' : 'error';
                return;
            }
            this._timer = setTimeout(() => this._tick(), 2000);
        },

        _isTerminal(status) {
            return status === 'done' || status === 'failed' || status === 'uncertain';
        },

        _stop() {
            if (this._timer) { clearTimeout(this._timer); this._timer = null; }
        },

        close() {
            this._stop();
            this.phase = 'idle';
        },
    });
});
</script>
```

- [ ] **Step 2: Verify it loads without errors**

Run: `php bin/console cache:clear` then load any page that uses the layout in a browser; open the console and run `Alpine.store('agentPresent').statusLabel('in_progress')`.
Expected: `{ text: "Realizando presentación…", kind: "working" }`, no console errors. (If no browser is available in the sandbox, verify the script is syntactically valid by checking the page renders and there is no Twig/JS parse error in `php bin/console lint:twig templates/layouts/app.html.twig`.)

- [ ] **Step 3: Commit (GATED — ask David first)**

```bash
git add templates/layouts/app.html.twig
git commit -m "feat: add shared agentPresent Alpine store for progress modal

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Shared modal partial bound to the store

**Files:**
- Create: `templates/_partials/_agent_present_modal.html.twig`
- Delete: `templates/complaint/_present_modal.html.twig` (after Tasks 4 update their includes — do the delete in Task 4 to avoid a broken intermediate include; here only create the new partial)

**Interfaces:**
- Consumes: `Alpine.store('agentPresent')` (Task 2).
- Produces: a self-contained modal (root `x-data="{}"`) usable on any page; relied on by Tasks 4–6 via `{% include '_partials/_agent_present_modal.html.twig' %}`.

- [ ] **Step 1: Create the partial**

Create `templates/_partials/_agent_present_modal.html.twig`. It keeps the existing fly-in animation + `<style>` (copy verbatim from `templates/complaint/_present_modal.html.twig` lines 92-143) and replaces the body with store-driven markup:

```twig
{# Shared "presentar con el agente" modal. Driven entirely by
   Alpine.store('agentPresent') (registered in layouts/app.html.twig).
   Include once on any page that submits via the agent. #}
<div x-data="{}"
     x-show="$store.agentPresent.phase !== 'idle'"
     x-cloak
     x-transition.opacity
     class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 backdrop-blur-[1px]"
     @keydown.escape.window="$store.agentPresent.close()"
     @click.self="$store.agentPresent.close()">

    <div class="bg-white rounded-xl shadow-xl max-w-md w-full mx-4 overflow-hidden border border-slate-200">

        {# header #}
        <div class="px-6 pt-6 pb-2">
            <h3 class="text-lg font-semibold text-slate-900">
                <span x-show="$store.agentPresent.phase === 'enqueuing'" x-cloak
                      x-text="'Encolando ' + $store.agentPresent.entityLabel + '…'"></span>
                <span x-show="$store.agentPresent.phase === 'tracking'" x-cloak>Procesando con el agente…</span>
                <span x-show="$store.agentPresent.phase === 'done'" x-cloak>Listo</span>
                <span x-show="$store.agentPresent.phase === 'error'" x-cloak>No se pudo completar</span>
            </h3>
            <p class="text-sm text-slate-500 mt-1"
               x-show="$store.agentPresent.phase === 'done' && $store.agentPresent.total > 1" x-cloak
               x-text="'Enviado a ' + $store.agentPresent.successCount + ' de ' + $store.agentPresent.total + ' organismos.'"></p>
        </div>

        {# animation (plays while enqueuing/tracking) #}
        <div class="px-6 py-6 flex items-center justify-center"
             x-show="$store.agentPresent.phase === 'enqueuing' || $store.agentPresent.phase === 'tracking'" x-cloak>
            <div class="present-anim" :class="{ 'is-stopped': !$store.agentPresent.busy }">
                <div class="present-anim-stage">
                    <span class="present-anim-source" title="PideInfo">
                        <i data-lucide="folder-open" class="w-6 h-6 text-slate-400"></i>
                    </span>
                    <span class="present-anim-track" aria-hidden="true">
                        <span class="present-anim-doc" data-doc="1"><i data-lucide="file-text" class="w-5 h-5 text-primary-600"></i></span>
                        <span class="present-anim-doc" data-doc="2"><i data-lucide="file-text" class="w-5 h-5 text-primary-600"></i></span>
                        <span class="present-anim-doc" data-doc="3"><i data-lucide="file-text" class="w-5 h-5 text-primary-600"></i></span>
                    </span>
                    <span class="present-anim-target" title="Agente / Sede">
                        <i data-lucide="cpu" class="w-6 h-6 text-emerald-500"></i>
                    </span>
                </div>
            </div>
        </div>

        {# per-task progress list (shown while tracking and on done/error) #}
        <div class="px-6 pb-2" x-show="$store.agentPresent.phase !== 'enqueuing' && $store.agentPresent.tasks.length" x-cloak>
            <ul class="space-y-2">
                <template x-for="t in $store.agentPresent.tasks" :key="t.id">
                    <li class="flex items-start gap-2 text-sm">
                        {# icon by kind #}
                        <span class="mt-0.5 shrink-0">
                            <i x-show="t.kind === 'waiting' || t.kind === 'working'" data-lucide="loader-2" class="w-4 h-4 text-primary-500 animate-spin"></i>
                            <i x-show="t.kind === 'done'" data-lucide="check-circle-2" class="w-4 h-4 text-emerald-500"></i>
                            <i x-show="t.kind === 'failed'" data-lucide="x-circle" class="w-4 h-4 text-red-500"></i>
                            <i x-show="t.kind === 'uncertain'" data-lucide="alert-triangle" class="w-4 h-4 text-amber-500"></i>
                        </span>
                        <span class="min-w-0">
                            <span class="block text-slate-700" x-show="t.bodyName" x-text="t.bodyName"></span>
                            <span class="block text-slate-500" x-text="t.label"></span>
                            <span class="block text-red-600 text-xs mt-0.5" x-show="t.errorMessage" x-text="t.errorMessage"></span>
                        </span>
                    </li>
                </template>
            </ul>
        </div>

        {# global error (POST/network failure before any task exists) #}
        <template x-if="$store.agentPresent.phase === 'error' && $store.agentPresent.globalError">
            <div class="px-6 py-4">
                <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-md px-3 py-2"
                     x-text="$store.agentPresent.globalError"></div>
            </div>
        </template>

        {# "task continues" reassurance while in flight #}
        <p class="px-6 text-xs text-slate-400"
           x-show="$store.agentPresent.busy" x-cloak>
            Puedes cerrar esta ventana: la tarea continuará en segundo plano.
        </p>

        {# footer #}
        <div class="px-6 pb-6 pt-3 flex items-center justify-end gap-2">
            <button type="button"
                    class="text-sm text-slate-500 hover:text-slate-800 px-3 py-1.5"
                    @click="$store.agentPresent.close()">
                Cerrar
            </button>
            {# single-task complaint: force the desktop agent to start now #}
            <a x-show="$store.agentPresent.busy && $store.agentPresent.tasks.length === 1 && $store.agentPresent.tasks[0].schemeUrl"
               x-cloak
               :href="$store.agentPresent.tasks.length ? $store.agentPresent.tasks[0].schemeUrl : '#'"
               class="btn btn-accent inline-flex items-center gap-1.5">
                <i data-lucide="zap" class="w-4 h-4"></i> Forzar inicio
            </a>
            {# bulk done: go to the list #}
            <a x-show="$store.agentPresent.phase === 'done' && $store.agentPresent.doneHref"
               x-cloak
               :href="$store.agentPresent.doneHref"
               class="btn btn-primary inline-flex items-center gap-1.5">
                <i data-lucide="list" class="w-4 h-4"></i> Ver mis solicitudes
            </a>
        </div>
    </div>
</div>

<style>
    .present-anim { width: 100%; }
    .present-anim-stage { position: relative; height: 64px; display: flex; align-items: center; justify-content: space-between; padding: 0 8px; }
    .present-anim-source, .present-anim-target { width: 56px; height: 56px; display: inline-flex; align-items: center; justify-content: center; border-radius: 14px; background: #f1f5f9; border: 1px solid #e2e8f0; flex-shrink: 0; }
    .present-anim-target { background: #ecfdf5; border-color: #a7f3d0; }
    .present-anim-track { position: absolute; top: 50%; left: 64px; right: 64px; height: 2px; background: linear-gradient(90deg, #cbd5e1, #d1fae5); transform: translateY(-50%); }
    .present-anim-doc { position: absolute; top: -10px; left: 0; width: 26px; height: 26px; display: inline-flex; align-items: center; justify-content: center; background: #fff; border: 1px solid #bae6fd; border-radius: 6px; box-shadow: 0 1px 3px rgba(15, 23, 42, .08); animation: present-doc-fly 2.4s linear infinite; }
    .present-anim-doc[data-doc="2"] { animation-delay: 0.8s; }
    .present-anim-doc[data-doc="3"] { animation-delay: 1.6s; }
    .present-anim.is-stopped .present-anim-doc { animation-play-state: paused; opacity: 0; }
    @keyframes present-doc-fly {
        0%   { transform: translateX(0)    scale(0.85); opacity: 0; }
        12%  { opacity: 1; }
        50%  { transform: translateX(50%)  scale(1);    opacity: 1; }
        88%  { opacity: 1; }
        100% { transform: translateX(100%) scale(0.85); opacity: 0; }
    }
</style>

{# Re-init lucide icons whenever the modal's task list changes #}
<script>
    document.addEventListener('alpine:initialized', () => { if (window.lucide) window.lucide.createIcons(); });
    document.addEventListener('alpine:init', () => {
        // re-render icons when tasks mutate (new rows / status icon swaps)
        if (window.Alpine) Alpine.effect(() => {
            const _ = Alpine.store('agentPresent') && Alpine.store('agentPresent').tasks.length;
            queueMicrotask(() => { if (window.lucide) window.lucide.createIcons(); });
        });
    });
</script>
```

Notes for the implementer:
- `loader-2`, `check-circle-2`, `x-circle`, `alert-triangle`, `list`, `zap` are valid Lucide icon names. `animate-spin` is a Tailwind utility → triggers a `tailwind:build` (Task 8).
- The `Alpine.effect` re-runs `lucide.createIcons()` when the task list/icons change, since `data-lucide` is replaced by inline SVG only on first render.

- [ ] **Step 2: Lint the new template**

Run: `php bin/console lint:twig templates/_partials/_agent_present_modal.html.twig`
Expected: `OK`.

- [ ] **Step 3: Commit (GATED — ask David first)**

```bash
git add templates/_partials/_agent_present_modal.html.twig
git commit -m "feat: add shared agent-present modal partial (store-driven)

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Flow A — complaint draft/redactar/interactive

**Files:**
- Modify: `templates/complaint/draft.html.twig`, `templates/complaint/redactar.html.twig`, `templates/complaint/interactive.html.twig`
- Delete: `templates/complaint/_present_modal.html.twig`

**Interfaces:**
- Consumes: `Alpine.store('agentPresent')` (Task 2), partial `_partials/_agent_present_modal.html.twig` (Task 3).
- Produces: nothing new.

For **each** of the three templates, apply the same four edits (the present logic and buttons are near-identical; locate by content):

- [ ] **Step 1: Swap the include path**

Replace `{% include 'complaint/_present_modal.html.twig' %}` with
`{% include '_partials/_agent_present_modal.html.twig' %}`.

- [ ] **Step 2: Refactor `presentViaAgent` to drive the store**

Find the host's `presentViaAgent(mode)` method and replace its body with (this is the `redactar`/`draft` form — `interactive` uses the same body, keep its own `request.id` path):

```js
async presentViaAgent(mode) {
    const store = this.$store.agentPresent;
    if (store.busy) return;
    if (!this.draftSavedAt) {
        await this.saveDraft();
        if (!this.draftSavedAt) return;
    }
    store.open('reclamación');
    try {
        const res = await fetch('{{ path('app_complaint_present_via_agent', {id: request.id}) }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ mode }),
        });
        const data = await res.json();
        if (!res.ok) { store.fail(data.error || 'No se pudo encolar la tarea.'); return; }
        store.track(
            [{ id: data.taskId, statusUrl: data.statusUrl, schemeUrl: data.schemeUrl }],
            { entityLabel: 'reclamación' },
        );
    } catch (e) {
        store.fail('Error de red.');
    }
},
```

- [ ] **Step 3: Remove the now-dead host state and update buttons**

- Delete the host `closePresentModal()` method and the `presentingMode`, `presentResult`, `presentError` properties from the host `x-data` (they are now in the store). Leave all unrelated state (`draftSavedAt`, `saveDraft`, etc.) intact.
- Update the two present buttons: change `:disabled="presentingMode !== null"` to `:disabled="$store.agentPresent.busy"`, and change the label expressions from `presentingMode === 'supervised' ? 'Encolando…' : '…'` to `$store.agentPresent.busy ? 'Encolando…' : 'Presentar (supervisado)'` (and the auto button analogously).

Example (redactar buttons):

```twig
<button type="button" x-show="editorReady" x-cloak
        @click="presentViaAgent('supervised')" :disabled="$store.agentPresent.busy"
        class="btn btn-secondary"
        title="El agente abre la sede con el formulario rellenado y tú revisas + envías">
    <i data-lucide="eye" class="w-4 h-4 mr-2"></i>
    <span x-text="$store.agentPresent.busy ? 'Encolando…' : 'Presentar (supervisado)'">Presentar (supervisado)</span>
</button>
<button type="button" x-show="editorReady" x-cloak
        @click="presentViaAgent('auto')" :disabled="$store.agentPresent.busy"
        class="btn btn-primary"
        title="El agente rellena y envía sin intervención">
    <i data-lucide="zap" class="w-4 h-4 mr-2"></i>
    <span x-text="$store.agentPresent.busy ? 'Encolando…' : 'Presentar (auto)'">Presentar (auto)</span>
</button>
```

- [ ] **Step 4: After all three templates are updated, delete the old partial**

```bash
git rm templates/complaint/_present_modal.html.twig
```

- [ ] **Step 5: Lint all touched templates**

Run: `php bin/console lint:twig templates/complaint/draft.html.twig templates/complaint/redactar.html.twig templates/complaint/interactive.html.twig`
Expected: `OK` for all.

- [ ] **Step 6: Manual smoke (browser)**

Open a complaint redactar page, click "Presentar (auto)". Expected: modal opens with animation → progress list appears with `Esperando que el agente comience la tarea…` and polls. (Full lifecycle verified in Task 8.)

- [ ] **Step 7: Commit (GATED — ask David first)**

```bash
git add templates/complaint/draft.html.twig templates/complaint/redactar.html.twig templates/complaint/interactive.html.twig
git rm --cached templates/complaint/_present_modal.html.twig 2>/dev/null; true
git commit -m "feat: drive complaint present modal from agentPresent store

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: Flow B — solicitudes/show (present a complaint), retire the Stimulus pill

**Files:**
- Modify: `templates/solicitudes/show.html.twig` (the `data-controller="agent-present"` block, ~lines 1605-1697)
- Delete: `assets/controllers/agent_present_controller.js`

**Interfaces:**
- Consumes: store (Task 2), partial (Task 3).
- Produces: nothing.

- [ ] **Step 1: Rewrite the agent block to use the store**

In `templates/solicitudes/show.html.twig`, the wrapper currently carries
`data-controller="agent-present" data-action="agent-present:submit->..." data-agent-present-*-value="..."` and contains a status pill (`data-agent-present-target="status"`), a fallback panel (`data-agent-present-target="fallback"`), and a mode-picker modal that dispatches `agent-present:submit`.

Replace it with a pure-Alpine block: keep the mode-picker modal, but on "Lanzar agente" POST to the present endpoint and call the store. Concretely:

- Remove from the wrapper `div`: `data-controller="agent-present"`, the `data-action`, and the three `data-agent-present-*-value` attributes. Keep the Alpine `x-data="{ openAgentModal: false, agentMode: 'supervised' }"` and extend it with a `presentNow()` method:

```twig
<div class="mt-4 pt-4 border-t border-amber-200/60 flex flex-wrap items-center gap-2"
     x-data="{
        openAgentModal: false,
        agentMode: 'supervised',
        async presentNow() {
            const store = this.$store.agentPresent;
            if (store.busy) return;
            this.openAgentModal = false;
            store.open('reclamación');
            try {
                const res = await fetch('{{ path('app_complaint_present_via_agent', {id: request.id}) }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ mode: this.agentMode }),
                });
                const data = await res.json();
                if (!res.ok) { store.fail(data.error || 'No se pudo encolar la tarea.'); return; }
                store.track([{ id: data.taskId, statusUrl: data.statusUrl, schemeUrl: data.schemeUrl }], { entityLabel: 'reclamación' });
            } catch (e) { store.fail('Error de red.'); }
        }
     }">
```

- Delete the pill `<span ... data-agent-present-target="status"></span>` and the entire fallback `<div ... data-agent-present-target="fallback"> … </div>` block (the "Forzar inicio" affordance now lives in the shared modal).
- Remove the `data-agent-present-target="mode"` attributes from the two radio inputs (no longer needed; `x-model="agentMode"` stays).
- Change the "Lanzar agente" button's `@click` from the `dispatchEvent(...)` expression to `@click="presentNow()"`.

- [ ] **Step 2: Include the shared modal partial**

Add `{% include '_partials/_agent_present_modal.html.twig' %}` once on the page (e.g. just after the agent block, or near the page root). Ensure it is included only once even if the block is inside a loop — place the include outside any `{% for %}` over documents. If the agent block is rendered per-document in a loop, move the include to the template's top-level body.

- [ ] **Step 3: Delete the Stimulus controller**

```bash
git rm assets/controllers/agent_present_controller.js
```

Confirm no remaining references: `grep -rn "agent-present\|agent_present_controller" templates/ assets/` should return nothing.

- [ ] **Step 4: Lint + controller registry check**

Run: `php bin/console lint:twig templates/solicitudes/show.html.twig`
Run: `grep -rn "agent-present" templates/ assets/` → expect empty.
Expected: lint `OK`, no stray references.

- [ ] **Step 5: Commit (GATED — ask David first)**

```bash
git add templates/solicitudes/show.html.twig
git rm --cached assets/controllers/agent_present_controller.js 2>/dev/null; true
git commit -m "refactor: unify solicitudes/show complaint present onto shared modal

Retires agent_present_controller.js and the status pill/fallback.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: Flow C — realizar bulk submission

**Files:**
- Modify: `src/Controller/AccessRequestController.php` (`dispatchBatch`, the per-task creation loop + the JSON response ~lines 794-854)
- Modify: `assets/controllers/realizar_canvas_controller.js` (JSON-success branch, ~lines 476-489)
- Modify: `templates/solicitudes/realizar/draft.html.twig` (include the modal)

**Interfaces:**
- Consumes: store (Task 2), partial (Task 3).
- Produces: dispatch JSON now includes `tasks: Array<{taskId, statusUrl, bodyName}>`.

- [ ] **Step 1: Backend — collect created tasks and return them**

In `dispatchBatch`, inside the `foreach ($drafts as $accessRequest)` loop, after the task is persisted (`$this->entityManager->persist($task); $dispatched++;`), accumulate task metadata. Declare `$createdTasks = [];` next to `$dispatched = 0;`, and in the loop add:

```php
$createdTasks[] = [
    'task' => $task,
    'bodyName' => $accessRequest->getPublicBody()->getName(),
];
```

(Keep the `AgentTask` object reference; its UUID is only final after flush.) Then, in the AJAX response branch, build the serialised list after `flush()`/`commit()`:

```php
if ($request->isXmlHttpRequest() || str_contains((string) $request->headers->get('Accept'), 'application/json')) {
    $tasks = [];
    foreach ($createdTasks as $entry) {
        /** @var AgentTask $t */
        $t = $entry['task'];
        $tasks[] = [
            'taskId'    => $t->getId()->toRfc4122(),
            'statusUrl' => $this->generateUrl('api_agent_tasks_get', ['id' => $t->getId()->toRfc4122()]),
            'bodyName'  => $entry['bodyName'],
        ];
    }

    return new JsonResponse([
        'dispatched' => $dispatched,
        'redirectUrl' => $this->generateUrl('app_solicitudes_index'),
        'tasks' => $tasks,
    ]);
}
```

Ensure `use App\Entity\AgentTask;` is present (it is — the loop already constructs `new AgentTask(...)`). Leave the non-AJAX flash/redirect branch untouched.

- [ ] **Step 2: Backend sanity check**

Run: `php bin/console lint:container` (or at least `php -l src/Controller/AccessRequestController.php`).
Expected: no syntax/DI errors.

- [ ] **Step 3: Frontend — drive the store instead of redirecting**

In `assets/controllers/realizar_canvas_controller.js`, the success branch currently does:

```js
if (response.ok && json?.redirectUrl) {
    window.location.href = json.redirectUrl;
    ...
}
```

Replace the redirect with store-driven tracking, keeping the redirect only as the empty-tasks fallback:

```js
if (response.ok && json?.redirectUrl) {
    if (Array.isArray(json.tasks) && json.tasks.length > 0 && window.Alpine?.store) {
        // clear the in-flight guard so the user can interact with the modal
        if (button) button.dataset.dispatchInFlight = '';
        window.Alpine.store('agentPresent').track(
            json.tasks.map(t => ({ id: t.taskId, statusUrl: t.statusUrl, bodyName: t.bodyName })),
            { entityLabel: 'solicitud', doneHref: json.redirectUrl },
        );
        return;
    }
    window.location.href = json.redirectUrl;
    return;
}
```

(Match the exact surrounding lines; preserve the `confirm`/`uncertain`/error branches above it.)

- [ ] **Step 4: Template — include the modal partial**

In `templates/solicitudes/realizar/draft.html.twig`, add once (outside the dispatch `<form>`, e.g. just before the closing of the main container):

```twig
{% include '_partials/_agent_present_modal.html.twig' %}
```

- [ ] **Step 5: Lint**

Run: `php bin/console lint:twig templates/solicitudes/realizar/draft.html.twig`
Expected: `OK`.

- [ ] **Step 6: Commit (GATED — ask David first)**

```bash
git add src/Controller/AccessRequestController.php assets/controllers/realizar_canvas_controller.js templates/solicitudes/realizar/draft.html.twig
git commit -m "feat: show progress modal for bulk solicitud submission

dispatchBatch now returns per-task statusUrl; realizar canvas tracks them in
the shared agentPresent modal instead of redirecting immediately.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: Documentation

**Files:**
- Modify: `docs/complaint-workflow.md`, `docs/request-workflow.md`

**Interfaces:** none.

- [ ] **Step 1: Document the unified modal**

In `docs/complaint-workflow.md` and `docs/request-workflow.md`, add a short subsection describing: the agent-present flow now shows a shared progress modal (`templates/_partials/_agent_present_modal.html.twig`) driven by `Alpine.store('agentPresent')`; it polls `GET /api/agent/tasks/{id}` every 2s and renders the `pending→claimed→in_progress→done|failed|uncertain` lifecycle; for bulk solicitud dispatch it tracks one row per organismo and offers "Ver mis solicitudes" on completion; closing the modal does not cancel the backend task. Note that `agent_present_controller.js` was retired.

- [ ] **Step 2: Commit (GATED — ask David first)**

```bash
git add docs/complaint-workflow.md docs/request-workflow.md
git commit -m "docs: document unified agent-present progress modal

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Task 8: Build + full manual QA

**Files:** none (verification only).

- [ ] **Step 1: Build assets**

Run: `php bin/console tailwind:build` (picks up `animate-spin` and any new utilities used in the partial).
Run: `php bin/console cache:clear`.
Expected: both succeed.

- [ ] **Step 2: Importmap / asset sanity**

Run: `php bin/console importmap:audit` (or load a page and confirm no 404s for controllers).
Confirm `grep -rn "agent-present\|agent_present_controller" templates/ assets/` is empty (controller fully retired).

- [ ] **Step 3: Manual QA checklist** (browser; use `start-vnc.sh` + `DISPLAY=:99` if a headed browser is needed)

1. Complaint redactar → "Presentar (auto)": modal animates, list shows `Esperando que el agente comience la tarea…`, then `Realizando presentación…`, then a green check + "Presentación realizada." on `done`.
2. Force a `failed` task (have the agent/API mark it failed): modal shows the red ✕ row with the real `errorMessage`; header reads "No se pudo completar".
3. `uncertain` task: amber row with "revísalo en la sede".
4. solicitudes/show "Presentar con el agente": same modal (no more pill/fallback).
5. realizar multi-organismo "Enviar a los N organismos": one row per organismo, rows update independently; on completion "Ver mis solicitudes" button navigates to the list. Single-organismo: one row.
6. Close the modal mid-flight (Esc / click-outside / Cerrar): modal closes; reopen the request/complaint page and confirm the task kept running (status reflects backend).
7. ⌘K: search a known solicitud in lowercase and UPPERCASE → identical results.

- [ ] **Step 4: Final review + summary to David**

Show `git diff master...feat/agent-present-modal-progress --stat` and the QA results. Ask David before any commit/push (per Global Constraints).

---

## Self-review (completed during planning)

- **Spec coverage:** §1.1 three flows → Tasks 4/5/6. §1.2 ⌘K → Task 1. §3 decisions (modal for all, label mapping, realizar "Ver mis solicitudes", retire Stimulus, no commits) → Tasks 2/3/4/5/6 + Global Constraints. §4 store/partial → Tasks 2/3. §5 wiring → Tasks 4/5/6. §6 search → Task 1. §8 testing → Task 8. §9 docs → Task 7. All covered.
- **Placeholders:** none — full code given for store, partial, controller edits, repo change, and the test.
- **Type consistency:** store surface (`open`/`track`/`fail`/`close`/`statusLabel`/`busy`) defined in Task 2 is used identically in Tasks 4/5/6; `track()` task shape `{id,statusUrl,schemeUrl?,bodyName?}` matches what each caller passes; dispatch JSON `tasks:[{taskId,statusUrl,bodyName}]` (Task 6 backend) is mapped to `{id,statusUrl,bodyName}` before `track()` (Task 6 frontend).
