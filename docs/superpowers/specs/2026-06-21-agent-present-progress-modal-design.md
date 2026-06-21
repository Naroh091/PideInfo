# Agent-present progress modal + CMD+K case-insensitive search

Date: 2026-06-21
Branch: `feat/agent-present-modal-progress` (worktree from `master`)

## 1. Background

Two unrelated issues, bundled because they were reported together.

### 1.1 The "stuck" present modal

When a user presents a **reclamación** (complaint) via the agent, an animated
modal appears (`templates/complaint/_present_modal.html.twig`) showing:

- `Encolando reclamación…` while the POST is in flight
- `Tu reclamación va de camino al agente` once the task is enqueued

It then **stops forever**. The backend already returns a `statusUrl`
(`api_agent_tasks_get`) the modal could poll, but the Alpine code ignores it.
So the user never learns whether the agent started, finished, or failed.

There are in fact **three** agent-submission entry points today, each wired
differently:

| # | Where | Today | Tasks |
|---|-------|-------|-------|
| A | `complaint/draft.html.twig`, `redactar.html.twig`, `interactive.html.twig` | Animated Alpine modal that never updates (`_present_modal.html.twig`), duplicated inline `presentViaAgent()` | 1 |
| B | `solicitudes/show.html.twig` (present a complaint from the request page) | Stimulus `agent_present_controller.js` → status **pill** + fallback panel (already polls, but no nice modal) | 1 |
| C | `solicitudes/realizar/draft.html.twig` (submit the request itself) | `realizar-canvas#dispatchSubmit` → **bulk** dispatch `app_solicitudes_realizar_dispatch` → returns `{dispatched, redirectUrl}` and redirects to the list. No modal, no status. | N (one per organismo) |

Goal: **all three** show the same animated "modal bonito" with a live progress
indicator, a done confirmation, an error state showing the real error message,
and a note that closing the dialog does not cancel the task.

### 1.2 CMD+K search is case-sensitive

The command palette (`templates/_partials/command_palette.html.twig`, opened
with ⌘K/Ctrl+K) fetches `app_solicitudes_search_json`, which calls
`AccessRequestRepository::searchForLinking()`. That method uses bare `LIKE` on
PostgreSQL (case-sensitive by default), so `"madrid"` and `"Madrid"` return
different results. The rest of the codebase (`ResolutionRepository`) uses the
`LOWER(col) LIKE LOWER(:param)` convention; `searchForLinking` is the outlier.

## 2. Backend facts (verified on `master`, f79e3b6)

- `AgentTask` statuses: `pending → claimed → in_progress → done | failed | uncertain`.
  Terminal = `{done, failed, uncertain}` (`AgentTask::TERMINAL_STATUSES`).
- `GET /api/agent/tasks/{id}` (`api_agent_tasks_get`) returns
  `{ id, type, mode, status, payload, result, errorMessage, createdAt, claimedAt, completedAt }`.
- Status reaches the browser **only by polling** — no SSE, no Mercure for tasks.
- Complaint present endpoints already return `{ taskId, schemeUrl, statusUrl }`
  (`ComplaintController` lines 613–615 and 772–774). `schemeUrl` is
  `pideinfo://present-complaint/{id}` (and `-reg` variant) to wake the desktop
  agent.
- The bulk request dispatch (`AccessRequestController::dispatchBatch`, route
  `app_solicitudes_realizar_dispatch`) creates one `AgentTask` per draft in the
  batch and currently returns only `{ dispatched, redirectUrl }`. There is **no**
  `pideinfo://` wake for request submissions — the agent discovers them by
  polling `GET /api/agent/tasks/pending`.

## 3. Decisions (agreed with David)

1. Apply the animated modal + progress to **all three** flows.
2. Status label mapping (the "recommended" mapping):
   - `pending`    → `Esperando que el agente comience la tarea…`
   - `claimed`    → `Agente conectado, preparando…`
   - `in_progress`→ `Realizando presentación…`
   - `done`       → confirmation
   - `failed`     → error block + real `errorMessage`
   - `uncertain`  → warning to verify manually in the sede
3. **realizar (flow C)**: stay on the page showing the modal with per-organismo
   progress; when all tasks are terminal, show a **"Ver mis solicitudes"** button
   that navigates to `redirectUrl`. (No auto-redirect.)
4. **Unify and retire** the Stimulus `agent_present_controller.js` + pill/fallback
   on `solicitudes/show.html.twig`; everything goes through the shared modal.
5. Work in the worktree; **no commits** without David's explicit OK
   (overrides the brainstorming default of committing the spec).

## 4. Architecture

One source of truth, reused by every flow: an **Alpine store** plus a single
shared modal partial. No polling logic is duplicated per host.

### 4.1 `Alpine.store('agentPresent')`

Registered once, inline in `templates/layouts/app.html.twig`, inside an
`alpine:init` listener placed **before** the Alpine CDN `<script>` so the
listener is attached before Alpine initialises. Plain JS, no module ordering
concerns (consistent with the codebase's inline-script convention).

State:

```
phase:       'idle' | 'enqueuing' | 'tracking' | 'done' | 'error'
tasks:       [ { id, statusUrl, schemeUrl|null, bodyName|null,
                 status, errorMessage, label, kind } ]
entityLabel: 'reclamación' | 'solicitud'
globalError: string | null
doneHref:    string | null   // "Ver mis solicitudes" target (flow C)
_timer:      number | null    // active poll timeout handle
```

Methods (the public interface every host uses):

- `open(entityLabel)` — sets `phase='enqueuing'`, `entityLabel`, clears state,
  shows the modal (the animation plays). Used while the POST is in flight.
- `track(tasks, { entityLabel, doneHref })` — seed `tasks` (normalising each to
  the shape above with `status='pending'`), set `phase='tracking'`, start
  polling. Accepts 1..N tasks.
- `fail(message)` — `phase='error'`, `globalError=message` (POST/network failure
  before any task exists).
- `poll()` — internal; every 2s fetch each non-terminal task's `statusUrl`,
  update `status/errorMessage/label/kind`. When **all** tasks are terminal,
  set `phase='done'` (or `'error'` if all failed — see §4.3) and stop.
- `close()` — clear `_timer`, set `phase='idle'`. **Never** cancels the backend
  task. (Re-opening later is not supported in this iteration; the user re-checks
  status on the request/complaint page.)
- `statusLabel(status)` — returns `{ text, kind }`, `kind ∈
  waiting|working|done|failed|uncertain`, used for the row label + icon.

Callable from Alpine (`$store.agentPresent`) **and** from Stimulus / plain JS
(`window.Alpine.store('agentPresent')`), which is what lets the realizar Stimulus
controller drive the same modal.

### 4.2 Shared modal partial

`templates/complaint/_present_modal.html.twig` is **generalised and moved** to
`templates/_partials/_agent_present_modal.html.twig` (it is no longer
complaint-specific). It:

- Keeps the existing fly-in animation and its `<style>` block unchanged. The
  animation plays while `phase ∈ {enqueuing, tracking}` and pauses/hides
  otherwise (reuse the existing `.is-stopped` binding driven by `phase`).
- Reads everything from `$store.agentPresent` (no host-scope coupling). Its root
  `x-data` is minimal (e.g. `x-data="{}"`) so it works on Alpine pages *and*
  Stimulus-only pages (realizar).
- Renders a **per-task progress list**: one row per task with `bodyName` (when
  present), the status label, and a spinner / check / ✕ icon by `kind`. Single
  task = a list of one.
- Header text keys off `phase` + `entityLabel`:
  `enqueuing` → `Encolando {entityLabel}…`; `tracking` → `Procesando…`;
  `done` → confirmation; `error` → `No se pudo completar`.
- `done` state: success confirmation; if `doneHref` is set, a primary
  **"Ver mis solicitudes"** button.
- `error`/`uncertain`: a red block per failed task showing its `errorMessage`,
  and for `uncertain` an amber note to verify in the sede.
- A persistent footer note in `tracking`/`enqueuing`:
  **"Puedes cerrar esta ventana: la tarea continuará en segundo plano."**
- Footer keeps a `Cerrar` button (`close()`). The single-task complaint flow
  keeps a "Forzar inicio" link bound to `tasks[0].schemeUrl` when present.

### 4.3 Aggregation rules (multi-task)

- `phase='done'` when every task is terminal **and at least one** is `done`.
  Failed/uncertain tasks are still listed with their messages.
- `phase='error'` only when every task is terminal and **none** is `done`.
- The header count reflects successes: `Enviado a N de M organismos`.

## 5. Wiring each entry point

### 5.1 Flow A — complaint draft/redactar/interactive

The three host templates keep their `presentViaAgent(mode)` (still need the
`saveDraft()` precondition). Refactor its body to:

```js
async presentViaAgent(mode) {
    const store = this.$store.agentPresent;
    if (store.phase === 'enqueuing' || store.phase === 'tracking') return;
    if (!this.draftSavedAt) { await this.saveDraft(); if (!this.draftSavedAt) return; }
    store.open('reclamación');
    try {
        const res = await fetch('{{ path('app_complaint_present_via_agent', {id: request.id}) }}', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ mode }),
        });
        const data = await res.json();
        if (!res.ok) { store.fail(data.error || 'No se pudo encolar la tarea.'); return; }
        store.track([{ id: data.taskId, statusUrl: data.statusUrl, schemeUrl: data.schemeUrl }],
                    { entityLabel: 'reclamación' });
    } catch (e) { store.fail('Error de red.'); }
}
```

The present buttons' `:disabled`/label switch from the removed `presentingMode`
to `$store.agentPresent.phase`. The old host state
(`presentingMode/presentResult/presentError/closePresentModal`) is removed.
Each host includes the moved partial path.

### 5.2 Flow B — solicitudes/show (present a complaint)

- Delete `assets/controllers/agent_present_controller.js`.
- Remove the `data-controller="agent-present"`, the pill (`data-...-target="status"`)
  and the fallback panel from `solicitudes/show.html.twig`.
- The existing Alpine mode-picker ("Lanzar agente") POSTs to
  `app_complaint_present_via_agent` and calls `$store.agentPresent.track(...)`
  exactly like flow A (no draft-save precondition here).
- Include the shared modal partial on the page.
- The label `switch` previously in the Stimulus controller now lives only in the
  store's `statusLabel`.

### 5.3 Flow C — realizar bulk submission

**Backend** (`AccessRequestController::dispatchBatch`): collect the created tasks
and add them to the JSON response. Each `new AgentTask(...)` is persisted in the
loop; after `flush()` their UUIDs are available. Build:

```php
$createdTasks[] = [
    'taskId'    => $task->getId()->toRfc4122(),
    'statusUrl' => $this->generateUrl('api_agent_tasks_get', ['id' => $task->getId()->toRfc4122()]),
    'bodyName'  => $accessRequest->getPublicBody()->getName(),
];
```

Response becomes `{ dispatched, redirectUrl, tasks: $createdTasks }`. (No
`schemeUrl` for requests — there is no `pideinfo://submit-request` handler; the
agent polls pending tasks, unchanged.) Existing flash/redirect for the non-AJAX
branch is preserved untouched.

**Frontend** (`assets/controllers/realizar_canvas_controller.js`): in the
JSON-success branch, instead of `window.location.href = json.redirectUrl`, call
`window.Alpine.store('agentPresent').track(json.tasks, { entityLabel: 'solicitud',
doneHref: json.redirectUrl })`. The confirm/uncertain/error branches are kept.
Edge case: if `json.tasks` is empty (nothing dispatched) keep the current
redirect.

**Template** (`solicitudes/realizar/draft.html.twig`): include the shared modal
partial so it is present in the DOM.

## 6. CMD+K fix

`AccessRequestRepository::searchForLinking()` — change the text predicate to be
case-insensitive, matching the `ResolutionRepository` convention:

```php
$qb->andWhere('LOWER(ar.title) LIKE LOWER(:q) OR LOWER(ar.externalId) LIKE LOWER(:q) '
            . 'OR LOWER(ar.description) LIKE LOWER(:q) OR LOWER(pb.name) LIKE LOWER(:q)')
   ->setParameter('q', '%' . $query . '%');
```

Apply the same `LOWER(pb.name) LIKE LOWER(:publicBody)` to the `publicBody`
filter in the same method for consistency (even though ⌘K only passes `q`).

## 7. Out of scope (YAGNI)

- No SSE/Mercure push for task status — polling is sufficient and matches the
  current model.
- No `pideinfo://submit-request` wake scheme for requests.
- No persistence of "minimised" modals across navigation; closing simply stops
  polling. Status remains visible on the request/complaint pages as today.
- No changes to the agent-side desktop app.

## 8. Testing & verification

Env limitation: only pure unit tests run here; DB-dependent tests are
baseline-failing (see project memory). Plan:

- **Status mapping**: if a JS test runner exists, a pure unit test of
  `statusLabel()` for all six statuses; otherwise manual verification.
- **CMD+K query**: change mirrors an existing proven pattern; verify the
  generated DQL/SQL by reading, plus manual QA (search "MADRID" vs "madrid").
- **Manual QA script** (documented in the plan):
  1. Complaint present (redactar) → modal cycles pending→claimed→in_progress→done.
  2. Force a failure (e.g. agent reports `failed`) → error message shown.
  3. realizar multi-organismo → N rows update independently; "Ver mis
     solicitudes" appears on completion.
  4. Close mid-flight → modal closes, task keeps running (re-check on page).
  5. ⌘K search case-insensitive.
- `php bin/console tailwind:build` after CSS/markup changes (per project memory);
  asset/importmap sanity check.

## 9. Affected files

- `templates/layouts/app.html.twig` — register `Alpine.store('agentPresent')`.
- `templates/complaint/_present_modal.html.twig` → move to
  `templates/_partials/_agent_present_modal.html.twig`, generalised.
- `templates/complaint/{draft,redactar,interactive}.html.twig` — refactor
  `presentViaAgent`, update buttons, fix include path.
- `templates/solicitudes/show.html.twig` — drop Stimulus pill, use modal.
- `templates/solicitudes/realizar/draft.html.twig` — include modal.
- `assets/controllers/agent_present_controller.js` — **delete**.
- `assets/controllers/realizar_canvas_controller.js` — drive the store.
- `src/Controller/AccessRequestController.php` — dispatch returns `tasks[]`.
- `src/Repository/AccessRequestRepository.php` — case-insensitive search.
- `docs/` — update relevant docs (complaint-workflow / request-workflow) to note
  the unified progress modal, per the project's "reflect updates in docs" rule.
```
