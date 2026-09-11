import { Controller } from '@hotwired/stimulus';

/**
 * Unified chat controller for the AI drafting assistant.
 *
 * - Streams server events (SSE over fetch + ReadableStream) so we can POST
 *   multipart with attachments — native EventSource is GET-only.
 * - The LLM auto-decides whether to reply with a question, generate the
 *   first draft, or rewrite the existing one (no buttons).
 * - The composer is a multi-line textarea (Shift+Enter for newline, Enter
 *   to send) and accepts file attachments that travel as ContentParts in
 *   memory only — no server-side persistence.
 * - When the assistant rewrites, the system bubble carries a "Ver cambios"
 *   button that opens a diff modal against the previous snapshot.
 *
 * Two mounting modes, decided by `canvasOutletSelector`:
 *
 * 1. Classic (floating widget): the value points at a canvas controller
 *    outside the chat; drafts leave the chat via `replaceContent()` on it.
 * 2. Conversation page (no canvas selector): the chat IS the page. Drafts
 *    render as an editable in-chat paper sheet (paper-sheet controller),
 *    cloned from `sheetTemplate` on first use and updated in place after.
 *    The "Informe preliminar" sidebar (rag-card controller) listens to
 *    `assistant-chat:draft-applied` / `paper-sheet:settled` window events to
 *    refresh itself. If the conversation is empty and there is no draft yet,
 *    a scripted intro turn (from `introTemplate`) offers "Redactar borrador
 *    con IA" / "Prefiero redactar manualmente" — purely visual, no backend
 *    call.
 *
 * Targets: fab, panel, history, input, attachInput, attachChips, sendButton,
 *          status, extra, introTemplate, sheetTemplate
 * Values:  endpointUrl, isReg, canvasOutletSelector, hasDraft
 */
export default class extends Controller {
    static targets = ['fab', 'panel', 'history', 'input', 'attachInput', 'attachChips', 'sendButton', 'status', 'extra', 'introTemplate', 'sheetTemplate'];
    static values = {
        endpointUrl: String,
        // GET del último turno del hilo (AssistantChatController::turn). Permite
        // recuperar un resultado cuyo stream SSE se cortó; vacío = sin recuperación.
        turnUrl: { type: String, default: '' },
        isReg: { type: Boolean, default: false },
        canvasOutletSelector: { type: String, default: '' },
        hasDraft: { type: Boolean, default: false },
        flow: { type: String, default: 'request' },
        // Flujo público /redactar: borrador sin dueño. Tras el primer borrador
        // generado se añade una burbuja invitando a crear cuenta (registerUrl).
        anonymous: { type: Boolean, default: false },
        registerUrl: { type: String, default: '' },
        // Hilo de trámite: marcas de tiempo reales del borrador (ISO 8601).
        createdAt: { type: String, default: '' },
        updatedAt: { type: String, default: '' },
    };

    connect() {
        this.pendingAttachments = [];
        this._busy = false;
        this._abort = null;
        this._maxFileBytes = 4 * 1024 * 1024;
        this._maxTotalBytes = 5 * 1024 * 1024;
        if (this.hasInputTarget) {
            this._growInput();
        }
        if (this._sheetMode() && this._historyIsEmpty() && !this.hasDraftValue) {
            this._renderIntro();
        } else {
            this._scrollToBottom();
        }
        this._initTimeline();
        if (this.turnUrlValue) this._resumePendingTurn();
    }

    disconnect() {
        if (this._abort) {
            try { this._abort.abort(); } catch {}
        }
        if (this._timelineTimer) clearInterval(this._timelineTimer);
    }

    /* ── Hilo de trámite: hitos reales (Borrador creado / Guardado hace X) ── */
    _initTimeline() {
        if (!this._sheetMode() || !this.hasHistoryTarget) return;

        if (this.createdAtValue) {
            const created = this._makeEvent('Borrador creado', this.createdAtValue);
            this.historyTarget.insertBefore(created, this.historyTarget.firstChild);
        }
        // Si ya había un borrador guardado al cargar, muestra el «Guardado hace X».
        if (this.hasDraftValue && this.updatedAtValue && this.updatedAtValue !== this.createdAtValue) {
            this._savedEvent = this._makeEvent('Guardado', this.updatedAtValue);
            this.historyTarget.appendChild(this._savedEvent);
        }
        this._timelineTimer = setInterval(() => this._refreshTimestamps(), 30000);
    }

    _makeEvent(prefix, isoTs) {
        const node = document.createElement('div');
        node.className = 'chat-event';
        const span = document.createElement('span');
        span.className = 'chat-event-text';
        span.dataset.prefix = prefix;
        if (isoTs) span.dataset.ts = isoTs;
        span.textContent = isoTs ? `${prefix} · ${this._relativeTime(isoTs)}` : prefix;
        node.appendChild(span);
        return node;
    }

    /** Save event bubbling up from the active paper sheet → update «Guardado hace X». */
    onDraftSaved() {
        const nowIso = new Date().toISOString();
        if (!this._savedEvent) this._savedEvent = this._makeEvent('Guardado', nowIso);
        const span = this._savedEvent.querySelector('.chat-event-text');
        span.dataset.prefix = 'Guardado';
        span.dataset.ts = nowIso;
        span.textContent = `Guardado · ${this._relativeTime(nowIso)}`;
        if (this.hasHistoryTarget) this.historyTarget.appendChild(this._savedEvent); // mantenlo al final
    }

    _refreshTimestamps() {
        if (!this.hasHistoryTarget) return;
        this.historyTarget.querySelectorAll('.chat-event-text[data-ts]').forEach((el) => {
            if (el.dataset.ts) el.textContent = `${el.dataset.prefix} · ${this._relativeTime(el.dataset.ts)}`;
        });
    }

    _relativeTime(iso) {
        const then = new Date(iso).getTime();
        if (Number.isNaN(then)) return '';
        const secs = Math.max(0, Math.round((Date.now() - then) / 1000));
        if (secs < 45) return 'ahora';
        const mins = Math.round(secs / 60);
        if (mins < 60) return `hace ${mins} min`;
        const hours = Math.round(mins / 60);
        if (hours < 24) return `hace ${hours} h`;
        return `hace ${Math.round(hours / 24)} d`;
    }

    /** Conversation-page mode: drafts live inside the chat as paper sheets. */
    _sheetMode() {
        return !this.canvasOutletSelectorValue;
    }

    open() {
        if (!this.hasPanelTarget) return;
        this.panelTarget.classList.add('is-open');
        this.fabTarget?.classList.add('is-active');
        if (this.hasInputTarget) {
            requestAnimationFrame(() => this.inputTarget.focus());
        }
    }

    close() {
        if (!this.hasPanelTarget) return;
        this.panelTarget.classList.remove('is-open');
        this.fabTarget?.classList.remove('is-active');
    }

    /**
     * Public API for outside controllers (e.g. the canvas wrapping the
     * dispatch button) to query whether a chat turn is mid-stream — the
     * decision callback that persists title/expone/solicita to the
     * AccessRequest hasn't run yet, so dispatching now would race against
     * a row that doesn't yet have the data the LLM is producing.
     */
    isBusy() {
        return !!this._busy;
    }

    /* ── Scripted intro (conversation page, purely visual) ─────────────── */

    _historyIsEmpty() {
        if (!this.hasHistoryTarget) return true;
        return this.historyTarget.querySelector('.chat-turn') === null;
    }

    _renderIntro() {
        if (!this.hasIntroTemplateTarget || !this.hasHistoryTarget) return;
        const node = this.introTemplateTarget.content.cloneNode(true);
        this.historyTarget.appendChild(node);
        this._reIcons();
    }

    /**
     * "Redactar borrador con IA" — starts the real agent flow. Complaints
     * already have context (expediente + documents), so the first SSE turn
     * fires immediately. Requests start empty: a scripted assistant bubble
     * (no LLM call) asks for the topic, and the user's answer becomes the
     * first real turn.
     */
    chooseAi(event) {
        event?.preventDefault();
        if (this.flowValue === 'complaint') {
            // The real first message plays the user-turn role; no echo bubble.
            this._settleIntro('ai', false);
            if (this.hasInputTarget) this.inputTarget.value = 'Redacta un primer borrador.';
            this.sendMessage();
            return;
        }
        this._settleIntro('ai');
        this._appendScriptedAssistant('Perfecto. Cuéntame qué información quieres pedir y para qué la necesitas — con eso redacto un primer borrador apoyado en la ley aplicable y en resoluciones parecidas. Si tienes documentos de contexto, adjúntalos.');
        if (this.hasInputTarget) {
            this.inputTarget.placeholder = 'Quiero pedir…';
            this.inputTarget.focus();
        }
    }

    /** Assistant-styled bubble written by the UI itself (no backend turn). */
    _appendScriptedAssistant(text) {
        const node = document.createElement('div');
        node.className = 'chat-turn chat-turn-assistant';
        const body = document.createElement('div');
        body.className = 'chat-bubble-text';
        body.textContent = text;
        node.appendChild(body);
        if (this.hasHistoryTarget) this.historyTarget.appendChild(node);
        this._scrollToBottom();
        return node;
    }

    /** "Prefiero redactar manualmente" — insert a blank editable sheet. */
    chooseManual(event) {
        event?.preventDefault();
        this._settleIntro('manual');
        const sheet = this._ensureSheetEl();
        if (sheet) {
            this._appendSystemBubble('✍ Escribe directamente sobre el documento. El asistente sigue disponible cuando lo necesites.');
            const focusable = sheet.querySelector('textarea, trix-editor');
            if (focusable) requestAnimationFrame(() => focusable.focus());
        }
    }

    /** Remove the intro buttons; optionally echo the choice as a user turn. */
    _settleIntro(choice, echo = true) {
        if (!this.hasHistoryTarget) return;
        const intro = this.historyTarget.querySelector('[data-intro-choice]');
        if (intro) intro.remove();
        if (!echo) return;
        const label = choice === 'ai' ? 'Redactar borrador con IA' : 'Prefiero redactar manualmente';
        this._appendUserBubble(label, []);
    }

    /* ── Composer ───────────────────────────────────────────────────────── */

    onKeydown(event) {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            this.sendMessage(event);
        }
    }

    onInput() {
        this._growInput();
    }

    _growInput() {
        const el = this.inputTarget;
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, 14 * 16) + 'px';
    }

    onAttach(event) {
        const input = event.currentTarget;
        this._addFiles(input?.files || []);
        if (input) input.value = '';
    }

    /** Add files (from the picker OR a drag-drop) to the pending attachments. */
    _addFiles(files) {
        for (const file of Array.from(files || [])) {
            if (file.size > this._maxFileBytes) {
                this._appendSystemBubble(`«${this._escape(file.name)}» supera el límite de 4 MB.`);
                continue;
            }
            const total = this.pendingAttachments.reduce((acc, f) => acc + f.size, 0) + file.size;
            if (total > this._maxTotalBytes) {
                this._appendSystemBubble('Conjunto de adjuntos > 5 MB; añade menos archivos.');
                break;
            }
            this.pendingAttachments.push(file);
        }
        this._renderChips();
    }

    /* ── Arrastrar y soltar archivos sobre el composer ──────────────────── */
    onDragOver(event) {
        const types = event.dataTransfer ? Array.from(event.dataTransfer.types || []) : [];
        if (!types.includes('Files')) return; // ignora arrastres de texto/selección
        event.preventDefault();
        event.dataTransfer.dropEffect = 'copy';
        event.currentTarget.classList.add('is-dragover');
    }

    onDragLeave(event) {
        // Solo al salir de la tarjeta, no al pasar sobre un hijo.
        if (event.currentTarget.contains(event.relatedTarget)) return;
        event.currentTarget.classList.remove('is-dragover');
    }

    onDrop(event) {
        event.preventDefault();
        event.currentTarget.classList.remove('is-dragover');
        const files = event.dataTransfer ? Array.from(event.dataTransfer.files || []) : [];
        if (files.length) this._addFiles(files);
    }

    removeAttachment(event) {
        const idx = parseInt(event.currentTarget?.dataset.index || '-1', 10);
        if (idx >= 0) {
            this.pendingAttachments.splice(idx, 1);
            this._renderChips();
        }
    }

    _renderChips() {
        if (!this.hasAttachChipsTarget) return;
        this.attachChipsTarget.innerHTML = this.pendingAttachments.map((f, i) => `
            <span class="composer-chip" title="${this._escape(f.name)}">
                <i data-lucide="paperclip" class="w-3 h-3"></i>
                <span class="composer-chip-name">${this._escape(this._shorten(f.name, 26))}</span>
                <span class="composer-chip-size">${this._humanSize(f.size)}</span>
                <button type="button" data-action="assistant-chat#removeAttachment" data-index="${i}" aria-label="Quitar">×</button>
            </span>
        `).join('');
        this._reIcons();
    }

    async sendMessage(event) {
        if (event && typeof event.preventDefault === 'function') event.preventDefault();
        if (this._busy) return;
        const message = (this.hasInputTarget ? this.inputTarget.value : '').trim();
        if (!message && this.pendingAttachments.length === 0) return;

        // Typing to the assistant implies choosing it — retire the intro buttons.
        const intro = this.hasHistoryTarget ? this.historyTarget.querySelector('[data-intro-choice]') : null;
        if (intro) intro.remove();

        this._busy = true;
        this._setBusy(true);

        const userBubble = this._appendUserBubble(message, this.pendingAttachments.map(f => f.name));
        // Reset the input + chips immediately for snappy UX.
        if (this.hasInputTarget) {
            this.inputTarget.value = '';
            this._growInput();
        }
        const sentAttachments = this.pendingAttachments.slice();
        this.pendingAttachments = [];
        this._renderChips();

        // Refresh the `extra` hidden inputs against fresh state. In sheet mode
        // the chat owns the sheet, so it fills currentBodyHtml itself; the
        // event stays for host pages (Alpine app on the classic complaint
        // editor) that refresh extras externally.
        this._refreshExtras();
        this.dispatch('before-send', { detail: { extras: this.hasExtraTarget ? this.extraTargets : [] } });

        // Flush any pending manual edits so the agent drafts from the user's
        // latest version, not a stale (debounced) one.
        const latestSheet = this._sheetEl();
        if (latestSheet) {
            const sheetCtrl = await this._sheetController(latestSheet);
            try { await sheetCtrl?.flush?.(); } catch (_e) { /* best-effort */ }
        }

        const formData = new FormData();
        formData.append('message', message);
        for (const f of sentAttachments) {
            formData.append('attachments[]', f, f.name);
        }
        // Extra hidden inputs declared by the host page (e.g. complaint mode,
        // current canvas HTML, selected document IDs).
        if (this.hasExtraTarget) {
            for (const el of this.extraTargets) {
                const name = el.getAttribute('name');
                if (!name) continue;
                formData.append(name, el.value ?? '');
            }
        }

        const assistantBubble = this._appendAssistantBubble('');
        const { tokens, ctx } = this._bubbleContext(assistantBubble);

        // Id del turno: el servidor guarda el resultado bajo este id para poder
        // recuperarlo si el stream se corta (AssistantTurnStore).
        const turnId = this._newTurnId();
        formData.append('turnId', turnId);

        this._abort = new AbortController();
        const signal = this._abort.signal;
        try {
            let response;
            try {
                response = await fetch(this.endpointUrlValue, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData,
                    signal,
                    headers: { 'Accept': 'text/event-stream' },
                });
            } catch (err) {
                if (!signal.aborted) {
                    this._handleSseEvent('error', { message: 'No se ha podido contactar con el asistente. Reintenta en unos segundos.' }, tokens, ctx);
                }
                return;
            }

            if (!response.ok || !response.body) {
                // Un 5xx de un proxy intermedio no implica que el turno haya muerto.
                if (response.status >= 500 && this.turnUrlValue) {
                    await this._recoverTurn(turnId, tokens, ctx);
                    return;
                }
                let detail = '';
                try { detail = (await response.json())?.message || ''; } catch {}
                this._handleSseEvent('error', { message: detail || `Error del servidor (${response.status}).` }, tokens, ctx);
                return;
            }

            let finished = false;
            const reader = response.body.getReader();
            const decoder = new TextDecoder('utf-8');
            let buffer = '';
            try {
                while (true) {
                    const { value, done } = await reader.read();
                    if (done) break;
                    buffer += decoder.decode(value, { stream: true });
                    let idx;
                    while ((idx = buffer.indexOf('\n\n')) !== -1) {
                        const raw = buffer.slice(0, idx);
                        buffer = buffer.slice(idx + 2);
                        if (this._processSseRecord(raw, tokens, ctx) === 'done') finished = true;
                    }
                }
            } catch (err) {
                // Stream cortado (red, proxy): el resultado se recupera abajo.
            }

            if (finished) {
                this._ackTurn(turnId);
            } else if (!signal.aborted) {
                await this._recoverTurn(turnId, tokens, ctx);
            }
        } finally {
            this._busy = false;
            this._setBusy(false);
            this._abort = null;
        }
    }

    /** Text node of an assistant bubble plus the context the SSE handlers need. */
    _bubbleContext(assistantBubble) {
        const tokens = assistantBubble.querySelector('.chat-bubble-text');
        const progress = assistantBubble.querySelector('.chat-bubble-progress');
        const progressCurrent = assistantBubble.querySelector('.chat-progress-current span:last-child');
        const progressSteps = assistantBubble.querySelector('.chat-progress-steps');
        const completedSteps = [];
        let progressShown = true;

        // Helper: transition from progress area to text area on first token.
        const activateText = () => {
            if (!progressShown) return;
            progressShown = false;
            if (progress) {
                if (completedSteps.length > 0) {
                    // Collapse completed steps into a <details> summary.
                    progress.innerHTML = `
                        <details class="chat-progress-history">
                            <summary>${completedSteps.length} paso${completedSteps.length !== 1 ? 's' : ''} previos</summary>
                            <div class="chat-progress-history-list">
                                ${completedSteps.map(s => `<div class="chat-progress-step-done" title="${this._escape(s)}">${this._escape(s)}</div>`).join('')}
                            </div>
                        </details>`;
                } else {
                    progress.remove();
                }
            }
            if (tokens) tokens.style.display = '';
            // Pin the view to the START of the generated response instead of
            // following the text to the bottom, so there's no jump when the
            // reply appears. After this one-time positioning we never auto-scroll
            // the reply, letting the user read from the beginning.
            this._pinToElement(assistantBubble);
        };

        return { tokens, ctx: { activateText, completedSteps, progressCurrent, progressSteps } };
    }

    /* ── Recuperación de turnos cuyo stream se cortó ────────────────────── */

    _newTurnId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
    }

    /** URL del turno, con el modo (reclamación/alegaciones) del hilo si lo hay. */
    _turnEndpoint(suffix = '', params = {}) {
        const url = new URL(this.turnUrlValue + suffix, window.location.origin);
        const modeInput = this.hasExtraTarget
            ? this.extraTargets.find((el) => el.getAttribute('name') === 'mode')
            : null;
        if (modeInput?.value) url.searchParams.set('mode', modeInput.value);
        for (const [key, value] of Object.entries(params)) url.searchParams.set(key, value);
        return url;
    }

    /** Último turno del hilo (o el turno `turnId`); null si no existe. */
    async _fetchTurn(turnId = null) {
        const response = await fetch(this._turnEndpoint('', turnId ? { turnId } : {}), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        });
        if (!response.ok) throw new Error(`turn status ${response.status}`);
        const data = await response.json();
        return data?.turn ?? null;
    }

    /** Marca el turno como entregado para que una recarga no lo aplique otra vez. */
    _ackTurn(turnId) {
        if (!this.turnUrlValue) return;
        fetch(this._turnEndpoint(`/${encodeURIComponent(turnId)}/entregado`), {
            method: 'POST',
            credentials: 'same-origin',
        }).catch(() => {});
    }

    /**
     * El stream terminó sin `done`, pero el turno sigue vivo en el servidor (o ya
     * terminó): sondea su registro hasta tener el resultado y lo reproduce en la
     * misma burbuja. El servidor mata el turno a los 900 s, así que 16 min basta.
     */
    async _recoverTurn(turnId, tokens, ctx) {
        if (!this.turnUrlValue) {
            this._handleSseEvent('error', { message: 'Se ha perdido la conexión con el asistente. Recarga la página para ver si la respuesta llegó a completarse.' }, tokens, ctx);
            return;
        }
        if (ctx.progressCurrent) ctx.progressCurrent.textContent = 'Se ha perdido la conexión. Recuperando la respuesta…';

        const deadline = Date.now() + 16 * 60 * 1000;
        while (Date.now() < deadline && this.element.isConnected) {
            await new Promise((resolve) => setTimeout(resolve, 5000));
            let turn;
            try {
                turn = await this._fetchTurn(turnId);
            } catch {
                continue; // la red puede seguir inestable: se reintenta
            }
            if (!turn) break; // el servidor no llegó a registrar este turno
            if (turn.status === 'running') continue;
            this._replayTurn(turn, tokens, ctx);
            this._ackTurn(turnId);
            return;
        }

        if (this.element.isConnected) {
            this._handleSseEvent('error', { message: 'No he podido recuperar la respuesta del asistente. Recarga la página en unos minutos o vuelve a intentarlo.' }, tokens, ctx);
        }
    }

    /** Reproduce en una burbuja los eventos guardados de un turno terminado. */
    _replayTurn(turn, tokens, ctx) {
        if (turn.status === 'lost') {
            this._handleSseEvent('error', { message: 'La respuesta del asistente no llegó a completarse. Vuelve a intentarlo.' }, tokens, ctx);
            return;
        }
        // Descarta lo que llegara a pintarse antes del corte: el registro trae la respuesta entera.
        tokens._raw = '';
        tokens.innerHTML = '';
        for (const [event, payload] of turn.events || []) {
            this._handleSseEvent(event, payload || {}, tokens, ctx);
        }
        this._handleSseEvent('done', {}, tokens, ctx);
    }

    /**
     * Al cargar la página: si el último turno del hilo sigue en marcha (el usuario
     * recargó mientras el modelo trabajaba) se engancha a él; si terminó sin que
     * nadie lo recibiera, aplica el borrador. La respuesta de texto ya la pinta el
     * servidor en el historial, y en solicitudes el borrador también se persiste al
     * decidir, así que ahí solo se confirma la entrega.
     */
    async _resumePendingTurn() {
        let turn;
        try {
            turn = await this._fetchTurn();
        } catch {
            return;
        }
        if (!turn || turn.deliveredAt || this._busy) return;

        if (turn.status === 'running') {
            if (this.hasHistoryTarget) this.historyTarget.querySelector('[data-intro-choice]')?.remove();
            this._busy = true;
            this._setBusy(true);
            const { tokens, ctx } = this._bubbleContext(this._appendAssistantBubble(''));
            if (ctx.progressCurrent) ctx.progressCurrent.textContent = 'Recuperando la respuesta en curso…';
            try {
                await this._recoverTurn(turn.turnId, tokens, ctx);
            } finally {
                this._busy = false;
                this._setBusy(false);
            }
            return;
        }

        const events = turn.events || [];
        const decision = events.find(([event]) => event === 'decision')?.[1];
        const error = events.find(([event]) => event === 'error')?.[1];
        if (turn.status === 'lost') {
            this._appendSystemBubble('⚠ La última respuesta del asistente no llegó a completarse. Vuelve a intentarlo.');
        } else if (decision?.draft && this.flowValue !== 'request') {
            this._applyDecision({ ...decision, plan: [], previous: null }, null);
        } else if (!decision && error?.message) {
            this._appendSystemBubble(`⚠ ${error.message}`);
        }
        this._ackTurn(turn.turnId);
    }

    /** Parses one SSE record and handles it. Returns the event name, or null if ignored. */
    _processSseRecord(raw, tokensEl, ctx = {}) {
        let event = 'message';
        let data = '';
        for (const line of raw.split('\n')) {
            if (line.startsWith(':')) continue;
            if (line.startsWith('event:')) event = line.slice(6).trim();
            else if (line.startsWith('data:')) data += line.slice(5).trim();
        }
        if (!data && event !== 'done') return null;
        let payload = {};
        try { payload = data ? JSON.parse(data) : {}; } catch { return null; }

        this._handleSseEvent(event, payload, tokensEl, ctx);
        return event;
    }

    /** Renders one event, live from the stream or replayed from a recovered turn. */
    _handleSseEvent(event, payload, tokensEl, ctx = {}) {
        const { activateText, completedSteps, progressCurrent, progressSteps } = ctx;

        switch (event) {
            case 'step': {
                const msg = String(payload.message ?? '');
                if (!msg) break;
                // Move previous current step into the completed list.
                if (progressCurrent && completedSteps && progressSteps) {
                    const prev = progressCurrent.textContent;
                    if (prev && prev !== 'Pensando…') {
                        completedSteps.push(prev);
                        const done = document.createElement('div');
                        done.className = 'chat-progress-step-done';
                        done.textContent = prev;
                        done.title = prev; // full text on hover (the line is truncated)
                        progressSteps.appendChild(done);
                        progressSteps.scrollTop = progressSteps.scrollHeight;
                    }
                    progressCurrent.textContent = msg;
                    // Replay the subtle fade on the running action line.
                    const currentLine = progressCurrent.parentElement;
                    if (currentLine) {
                        currentLine.style.animation = 'none';
                        void currentLine.offsetWidth;
                        currentLine.style.animation = '';
                    }
                }
                this._scrollToBottom();
                break;
            }
            case 'chat_token':
                if (activateText) activateText();
                // Accumulate the agent's HTML and render it (sanitized). No scroll —
                // the view stays at the START of the reply (set by activateText).
                tokensEl._raw = (tokensEl._raw || '') + String(payload.text ?? '');
                tokensEl.classList.add('chat-md');
                tokensEl.innerHTML = this._renderHtml(tokensEl._raw);
                break;
            case 'decision':
                if (activateText) activateText();
                this._applyDecision(payload, tokensEl);
                break;
            case 'error':
                if (activateText) activateText();
                tokensEl._raw = (tokensEl._raw || '') + '<p>⚠ ' + this._escape(payload.message || '') + '</p>';
                tokensEl.classList.add('chat-md');
                tokensEl.innerHTML = this._renderHtml(tokensEl._raw);
                break;
            case 'done':
                if (activateText) activateText();
                if (!tokensEl.textContent) {
                    tokensEl.textContent = '(sin respuesta)';
                }
                break;
        }
    }

    _applyDecision({ action, draft, previous, plan, sources }, tokensEl) {
        // FASE 1 plan: render each administration argument + dismantling strategy
        // as a card right under the reply.
        if (Array.isArray(plan) && plan.length) {
            this._renderPlanCards(plan, tokensEl);
        }

        if (action === 'reply' || !draft) {
            return;
        }

        const payload = this.isRegValue
            ? { title: draft.title, expone: draft.expone, solicita: draft.solicita, sources }
            : { title: draft.title, bodyHtml: draft.body_text || draft.body_html || '', sources };
        // Consulta libre: el agente clasifica el documento; llevamos su tipo a la
        // hoja para preseleccionar el <select> de "Guardar en el expediente".
        if (this.flowValue === 'consult' && draft.doc_type) {
            payload.docType = draft.doc_type;
        }

        if (this._sheetMode()) {
            this._applyDraftToSheet(payload);
        } else {
            const canvas = this._canvasOutlet();
            if (canvas && typeof canvas.replaceContent === 'function') {
                try { canvas.replaceContent(payload); } catch (e) { console.error(e); }
            }
        }

        const verb = action === 'generate' ? 'generado' : 'reescrito';
        const bubble = this._appendSystemBubble(`✦ Borrador ${verb}.`);
        if (previous) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'chat-bubble-diff-btn';
            button.textContent = 'Ver cambios';
            button.dataset.controller = 'diff-modal';
            button.dataset.action = 'diff-modal#open';
            button.dataset.diffModalPreviousValue = JSON.stringify(previous);
            button.dataset.diffModalCurrentValue = JSON.stringify(draft);
            bubble.appendChild(button);
        }

        this._maybeSuggestRegister();
    }

    // Anónimos: una única invitación a crear cuenta, tras el primer borrador
    // aplicado — el momento en que hay algo que merece conservarse.
    _maybeSuggestRegister() {
        if (!this.anonymousValue || !this.registerUrlValue || this._registerSuggested) return;
        this._registerSuggested = true;
        const bubble = this._appendSystemBubble(
            'Este borrador vive solo en este navegador. Si creas una cuenta gratuita lo guardamos en tu panel, vigilamos los plazos y podrás presentarlo desde aquí.',
        );
        const link = document.createElement('a');
        link.className = 'chat-bubble-diff-btn';
        link.href = this.registerUrlValue;
        link.textContent = 'Crear cuenta y conservarlo';
        bubble.appendChild(link);
    }

    /* ── In-chat paper sheet (conversation page) ────────────────────────── */

    /**
     * Append model: every generation/rewrite lands as its OWN document in the
     * stream, in chat order. We freeze the previous latest sheet into a
     * read-only snapshot and append a fresh one, then run the typewriter (which
     * draws the box first). Only the latest sheet is editable / autosaved /
     * submittable; the single draft persisted server-side is always the latest.
     */
    async _applyDraftToSheet(payload) {
        const prev = this._sheetEl();
        if (prev) {
            const prevCtrl = await this._sheetController(prev);
            prevCtrl?.freeze?.();
        }
        const sheetEl = this._createSheet();
        if (!sheetEl) return;
        const ctrl = await this._sheetController(sheetEl);
        if (!ctrl || typeof ctrl.replaceContent !== 'function') {
            console.error('assistant-chat: paper-sheet controller not available');
            return;
        }
        this._pinToElement(sheetEl);
        try { await ctrl.replaceContent(payload); } catch (e) { console.error(e); }
        if (this.flowValue === 'consult' && payload.docType) {
            // El título se teclea en el campo `title`; solo hay que preseleccionar
            // el tipo sugerido por el agente en el desplegable de guardado.
            const sel = sheetEl.querySelector('[data-paper-sheet-target~="docTypeSelect"]');
            if (sel) sel.value = payload.docType;
        }
        this.dispatch('draft-applied');
    }

    /** The latest (editable) sheet in the stream, or null. */
    _sheetEl() {
        if (!this.hasHistoryTarget) return null;
        const sheets = this.historyTarget.querySelectorAll('[data-controller~="paper-sheet"]');
        return sheets.length ? sheets[sheets.length - 1] : null;
    }

    /** Clone the blank sheet template and append it as a new document node. */
    _createSheet() {
        if (!this.hasSheetTemplateTarget || !this.hasHistoryTarget) return null;
        this.historyTarget.appendChild(this.sheetTemplateTarget.content.cloneNode(true));
        this._reIcons();
        return this._sheetEl();
    }

    /** Ensure at least one editable sheet exists (manual-drafting path). */
    _ensureSheetEl() {
        return this._sheetEl() || this._createSheet();
    }

    /**
     * Waits for Stimulus to connect the sheet controller. Uses setTimeout (not
     * requestAnimationFrame) so it still resolves when the tab is backgrounded:
     * rAF is paused in hidden tabs, which would otherwise hang the whole draft
     * render if the user switches away mid-generation.
     */
    async _sheetController(el) {
        for (let i = 0; i < 60; i++) {
            const ctrl = this.application.getControllerForElementAndIdentifier(el, 'paper-sheet');
            if (ctrl) return ctrl;
            await new Promise((resolve) => setTimeout(resolve, 16));
        }
        return null;
    }

    /** Sheet mode: fill the currentBodyHtml extra from the page. */
    _refreshExtras() {
        if (!this._sheetMode() || !this.hasExtraTarget) return;
        const bodyInput = this.extraTargets.find((el) => el.getAttribute('name') === 'currentBodyHtml');
        if (bodyInput) {
            const sheetEl = this._sheetEl();
            const htmlInput = sheetEl?.querySelector('[data-paper-sheet-target~="htmlInput"]');
            bodyInput.value = htmlInput?.value ?? '';
        }
    }

    _canvasOutlet() {
        if (!this.canvasOutletSelectorValue) return null;
        const el = document.querySelector(this.canvasOutletSelectorValue);
        if (!el) return null;
        const app = this.application;
        const controllerName = el.dataset.controller || '';
        const name = controllerName.split(/\s+/).find(c => c.endsWith('canvas')) || controllerName.split(/\s+/)[0];
        if (!app || !name) return null;
        return app.getControllerForElementAndIdentifier(el, name);
    }

    /* ── Bubbles ────────────────────────────────────────────────────────── */

    _appendUserBubble(message, attachmentNames) {
        const node = document.createElement('div');
        node.className = 'chat-turn chat-turn-user';
        if (message) {
            const text = document.createElement('div');
            text.className = 'chat-bubble-text';
            text.textContent = message;
            node.appendChild(text);
        }
        if (attachmentNames && attachmentNames.length) {
            const meta = document.createElement('div');
            meta.className = 'chat-bubble-attachments';
            meta.textContent = '📎 ' + attachmentNames.join(', ');
            node.appendChild(meta);
        }
        if (this.hasHistoryTarget) {
            this.historyTarget.appendChild(node);
        }
        // Scroll al primer mensaje nuevo: llevamos el mensaje del usuario arriba
        // del viewport y dejamos crecer la respuesta + el documento debajo.
        this._pinToElement(node);
        return node;
    }

    _appendAssistantBubble(initialText) {
        const node = document.createElement('div');
        node.className = 'chat-turn chat-turn-assistant';

        // Progress area — shown while tool calls run; hidden once chat_token arrives.
        const progress = document.createElement('div');
        progress.className = 'chat-bubble-progress';
        progress.innerHTML = `
            <div class="chat-progress-steps"></div>
            <div class="chat-progress-current">
                <span class="chat-step-spinner"></span>
                <span>Pensando…</span>
            </div>`;
        node.appendChild(progress);

        // Text area — hidden initially, shown once chat_token arrives.
        const text = document.createElement('div');
        text.className = 'chat-bubble-text';
        text.style.display = 'none';
        text.textContent = initialText || '';
        node.appendChild(text);

        if (this.hasHistoryTarget) {
            this.historyTarget.appendChild(node);
        }
        this._scrollToBottom();
        return node;
    }

    _appendSystemBubble(text) {
        const node = document.createElement('div');
        node.className = 'chat-turn chat-turn-system';
        const span = document.createElement('span');
        span.textContent = text;
        node.appendChild(span);
        if (this.hasHistoryTarget) {
            this.historyTarget.appendChild(node);
        }
        this._scrollToBottom();
        return node;
    }

    /* ── Scrolling ──────────────────────────────────────────────────────── */

    /**
     * The history target is a scrollable box in the floating widget but a
     * plain page column on the conversation page — there the window scrolls.
     */
    _scrollRoot() {
        if (!this.hasHistoryTarget) return window;
        const overflowY = getComputedStyle(this.historyTarget).overflowY;
        return (overflowY === 'auto' || overflowY === 'scroll') ? this.historyTarget : window;
    }

    _scrollToBottom() {
        const root = this._scrollRoot();
        if (root === window) {
            // Follow the END OF THE CONVERSATION, not the document: the
            // sidebar can be taller than the chat column, and scrolling to
            // the document bottom would leave the conversation out of view.
            const last = this.hasHistoryTarget ? this.historyTarget.lastElementChild : null;
            if (last) last.scrollIntoView({ block: 'end' });
            else window.scrollTo({ top: document.documentElement.scrollHeight });
        } else {
            root.scrollTop = root.scrollHeight;
        }
    }

    /** Positions the viewport so `el`'s top edge is comfortably in view. */
    _pinToElement(el) {
        const root = this._scrollRoot();
        const rect = el.getBoundingClientRect();
        if (root === window) {
            window.scrollTo({ top: window.scrollY + rect.top - 90 });
        } else {
            const rb = root.getBoundingClientRect();
            root.scrollTop += (rect.top - rb.top) - 8;
        }
    }

    _setBusy(busy) {
        if (this.hasSendButtonTarget) {
            this.sendButtonTarget.disabled = !!busy;
        }
        if (this.hasInputTarget) {
            this.inputTarget.disabled = !!busy;
        }
    }

    _humanSize(bytes) {
        if (bytes < 1024) return `${bytes} B`;
        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
        return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
    }

    _shorten(s, max) {
        if (!s || s.length <= max) return s;
        return s.slice(0, max - 1) + '…';
    }

    _escape(s) {
        return String(s).replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c]));
    }

    _reIcons() {
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            try { window.lucide.createIcons(); } catch {}
        }
    }

    /**
     * Sanitize agent-supplied HTML against an allowlist before it is inserted
     * with innerHTML. The agent emits HTML directly (no Markdown step), but its
     * output is still filtered: disallowed elements are unwrapped, dangerous ones
     * (script/style/iframe…) removed, and every attribute except a safe `href`
     * on links is stripped. Parsing happens in an inert <template>, so nothing
     * executes or loads during sanitization.
     */
    _renderHtml(raw) {
        const ALLOWED = new Set(['P', 'BR', 'STRONG', 'B', 'EM', 'I', 'U', 'UL', 'OL', 'LI', 'A', 'CODE', 'SPAN', 'BLOCKQUOTE', 'H4', 'H5']);
        const REMOVE = new Set(['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'LINK', 'META', 'SVG', 'MATH', 'IMG', 'FORM', 'INPUT', 'BUTTON']);

        const tpl = document.createElement('template');
        tpl.innerHTML = String(raw);

        const sanitize = (node) => {
            for (const child of Array.from(node.childNodes)) {
                if (child.nodeType === Node.COMMENT_NODE) { child.remove(); continue; }
                if (child.nodeType !== Node.ELEMENT_NODE) continue;
                const tag = child.tagName;
                if (REMOVE.has(tag)) { child.remove(); continue; }
                if (!ALLOWED.has(tag)) { sanitize(child); child.replaceWith(...child.childNodes); continue; }
                for (const attr of Array.from(child.attributes)) {
                    const name = attr.name.toLowerCase();
                    const okHref = tag === 'A' && name === 'href' && /^(https?:|mailto:)/i.test(attr.value.trim());
                    if (!okHref) child.removeAttribute(attr.name);
                }
                if (tag === 'A') { child.setAttribute('target', '_blank'); child.setAttribute('rel', 'noopener noreferrer'); }
                sanitize(child);
            }
        };
        sanitize(tpl.content);
        return tpl.innerHTML;
    }

    /**
     * Render the FASE 1 plan as cards: one per administration argument, each
     * showing what the administration claims and how it will be dismantled.
     * Appended under the reply text in the same assistant bubble.
     *
     * @param {Array<{argument: string, strategy: string}>} plan
     */
    _renderPlanCards(plan, tokensEl) {
        const bubble = tokensEl.closest('.chat-turn-assistant') || tokensEl.parentElement;
        if (!bubble) return;
        let wrap = bubble.querySelector('.chat-cards');
        if (!wrap) {
            wrap = document.createElement('div');
            wrap.className = 'chat-cards';
            bubble.appendChild(wrap);
        }
        wrap.innerHTML = plan.map((p, i) => `
            <div class="chat-card">
                <div class="chat-card-num">${i + 1}</div>
                <div class="chat-card-body">
                    <div class="chat-card-field">
                        <span class="chat-card-label">Argumento de la Administración</span>
                        <span class="chat-card-arg">${this._escape(p.argument || '')}</span>
                    </div>
                    <div class="chat-card-field">
                        <span class="chat-card-label">Cómo lo desmontamos</span>
                        <span class="chat-card-strategy">${this._escape(p.strategy || '')}</span>
                    </div>
                </div>
            </div>`).join('');
        // Keep the start of the reply in view (don't jump past the cards).
        this._pinToElement(bubble);
    }
}
