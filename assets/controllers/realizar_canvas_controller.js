import { Controller } from '@hotwired/stimulus';

/**
 * Canvas para "redactar la solicitud":
 *   - Autoguardado debounced (800 ms) en cada input.
 *   - Indicador "Guardado hace Xs" en la barra superior.
 *   - Evento `realizar-canvas:settled` (600 ms sin cambios) para que el
 *     sidebar RAG refresque resoluciones similares y probabilidad.
 *   - Método público `replaceContent({title, bodyHtml})` que escribe el
 *     borrador con animación de máquina de escribir, sin disparar autosave
 *     en cada tick (sólo uno al final). Tecla Escape salta al final.
 *
 * Targets: title, body, status
 * Values: autosaveUrl
 */
export default class extends Controller {
    static targets = ['title', 'body', 'expone', 'solicita', 'status'];
    static values = {
        autosaveUrl: String,
    };

    connect() {
        this._lastSavedAt = null;
        this._lastPayload = null;
        this._saveTimer = null;
        this._settleTimer = null;
        this._tickTimer = null;
        this._typing = false;
        this._typingAbort = null;

        this._onInput = this._onInput.bind(this);
        this._onKeyDown = this._onKeyDown.bind(this);
        this._onTitleInput = this._onTitleInput.bind(this);
        this._onBodyInput = this._onBodyInput.bind(this);
        if (this.hasTitleTarget) {
            this.titleTarget.addEventListener('input', this._onInput);
            this.titleTarget.addEventListener('input', this._onTitleInput);
            // Initial grow for prefilled drafts.
            requestAnimationFrame(() => this._growTitle());
        }
        for (const el of this._bodyTargets()) {
            el.addEventListener('input', this._onInput);
            el.addEventListener('input', this._onBodyInput);
            requestAnimationFrame(() => this._growBody(el));
        }
        document.addEventListener('keydown', this._onKeyDown);

        this._renderStatus();
    }

    _onTitleInput() {
        // Tame Enter — title is single-paragraph by convention.
        if (this.hasTitleTarget) {
            this.titleTarget.value = this.titleTarget.value.replace(/\n/g, ' ');
            this._growTitle();
        }
    }

    _growTitle() {
        if (!this.hasTitleTarget) return;
        const el = this.titleTarget;
        // `field-sizing: content` does this for us when supported. Keep the
        // JS fallback for browsers that haven't shipped it yet.
        el.style.height = 'auto';
        el.style.height = el.scrollHeight + 'px';
    }

    _bodyTargets() {
        const list = [];
        if (this.hasBodyTarget) list.push(this.bodyTarget);
        if (this.hasExponeTarget) list.push(this.exponeTarget);
        if (this.hasSolicitaTarget) list.push(this.solicitaTarget);
        return list;
    }

    _growBody(el) {
        if (!el) return;
        el.style.height = 'auto';
        el.style.height = el.scrollHeight + 'px';
    }

    _onBodyInput(event) {
        this._growBody(event.currentTarget);
    }

    disconnect() {
        if (this.hasTitleTarget) {
            this.titleTarget.removeEventListener('input', this._onInput);
            this.titleTarget.removeEventListener('input', this._onTitleInput);
        }
        for (const el of this._bodyTargets()) {
            el.removeEventListener('input', this._onInput);
            el.removeEventListener('input', this._onBodyInput);
        }
        document.removeEventListener('keydown', this._onKeyDown);
        if (this._saveTimer) clearTimeout(this._saveTimer);
        if (this._settleTimer) clearTimeout(this._settleTimer);
        if (this._tickTimer) clearInterval(this._tickTimer);
        if (this._typingAbort) this._typingAbort();
    }

    _onInput() {
        if (this._typing) return; // suppress autosave/settle while we're typing programmatically

        if (this.hasStatusTarget) {
            this.statusTarget.textContent = 'Cambios sin guardar…';
            this.statusTarget.classList.remove('canvas-status-saved');
        }

        if (this._saveTimer) clearTimeout(this._saveTimer);
        this._saveTimer = setTimeout(() => this._save(), 800);

        if (this._settleTimer) clearTimeout(this._settleTimer);
        this._settleTimer = setTimeout(() => {
            this.dispatch('settled', { detail: this._currentPayload() });
        }, 600);
    }

    _onKeyDown(event) {
        if (event.key === 'Escape' && this._typing && this._typingAbort) {
            this._typingAbort();
        }
    }

    /**
     * Public — invoked by the chat controller after the assistant returns
     * a replace-canvas response. Animates the title and body with a
     * typewriter effect, suspending autosave during the animation and
     * flushing exactly once when it ends. Pressing Escape jumps to the end.
     *
     * @param {{title?: string, bodyHtml?: string}} payload
     */
    async replaceContent({ title = '', bodyHtml = '', expone = '', solicita = '' } = {}) {
        const plainBody = this._htmlToPlain(bodyHtml).slice(0, 3000);
        const safeTitle = String(title || '').slice(0, 255);
        const safeExpone = this._htmlToPlain(expone).slice(0, 4000);
        const safeSolicita = this._htmlToPlain(solicita).slice(0, 4000);

        if (this._typingAbort) this._typingAbort();
        this._typing = true;

        if (this.hasStatusTarget) {
            this.statusTarget.textContent = 'Asistente escribiendo…';
            this.statusTarget.classList.remove('canvas-status-saved');
        }

        // Disable inputs while typing so concurrent edits don't fight us.
        const lockTargets = [];
        if (this.hasTitleTarget) lockTargets.push(this.titleTarget);
        if (this.hasBodyTarget) lockTargets.push(this.bodyTarget);
        if (this.hasExponeTarget) lockTargets.push(this.exponeTarget);
        if (this.hasSolicitaTarget) lockTargets.push(this.solicitaTarget);
        lockTargets.forEach((el) => {
            el.readOnly = true;
            el.value = '';
            el.classList.add('canvas-typing');
        });

        let aborted = false;
        const abortPromise = new Promise((resolve) => {
            this._typingAbort = () => { aborted = true; resolve(); };
        });

        try {
            // Title: visually quick (≈350 ms total) so the page feels alive.
            if (this.hasTitleTarget && safeTitle.length > 0) {
                await this._typeInto(this.titleTarget, safeTitle, {
                    chunk: Math.max(1, Math.ceil(safeTitle.length / 18)),
                    intervalMs: 18,
                    isAborted: () => aborted,
                    onTick: () => this._growTitle(),
                });
                if (aborted) this.titleTarget.value = safeTitle;
                this._growTitle();
            }

            // Body / expone / solicita: ~3 s total each.
            const typeBlock = async (el, text) => {
                if (text.length === 0 || aborted) return;
                const targetMs = 3000;
                const intervalMs = 14;
                const ticks = Math.max(1, Math.floor(targetMs / intervalMs));
                const chunk = Math.max(1, Math.ceil(text.length / ticks));
                await this._typeInto(el, text, {
                    chunk,
                    intervalMs,
                    isAborted: () => aborted,
                    onTick: () => this._growBody(el),
                });
                if (aborted) el.value = text;
                this._growBody(el);
            };

            if (this.hasBodyTarget && plainBody.length > 0) await typeBlock(this.bodyTarget, plainBody);
            if (this.hasExponeTarget && safeExpone.length > 0) await typeBlock(this.exponeTarget, safeExpone);
            if (this.hasSolicitaTarget && safeSolicita.length > 0) await typeBlock(this.solicitaTarget, safeSolicita);
        } finally {
            this._typing = false;
            this._typingAbort = null;
            lockTargets.forEach((el) => {
                el.readOnly = false;
                el.classList.remove('canvas-typing');
            });
        }

        // One save at the end, plus one settled-event so the sidebar refreshes.
        await this._save();
        this.dispatch('settled', { detail: this._currentPayload() });

        // Resolve the abort promise if we finished naturally (so any awaiters unblock).
        // (No-op if already resolved by Esc.)
        await abortPromise.catch(() => {});
    }

    /**
     * Writes text into an input/textarea by appending fixed-size chunks
     * at a steady interval. Cooperatively cancellable via isAborted().
     */
    async _typeInto(el, text, { chunk, intervalMs, isAborted, onTick }) {
        let i = 0;
        return new Promise((resolve) => {
            const tick = () => {
                if (isAborted()) {
                    resolve();
                    return;
                }
                i = Math.min(text.length, i + chunk);
                el.value = text.slice(0, i);
                if (typeof onTick === 'function') onTick();
                if (i >= text.length) {
                    resolve();
                    return;
                }
                setTimeout(tick, intervalMs);
            };
            tick();
        });
    }

    async _save() {
        const payload = this._currentPayload();
        if (this._payloadEquals(payload, this._lastPayload)) return;

        try {
            const response = await fetch(this.autosaveUrlValue, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const json = await response.json();
            this._lastSavedAt = json.savedAt ? new Date(json.savedAt) : new Date();
            this._lastPayload = payload;
            this._renderStatus();
            this._scheduleStatusTick();
            this.dispatch('saved', { detail: { savedAt: this._lastSavedAt } });
        } catch (err) {
            console.error('autosave failed', err);
            if (this.hasStatusTarget) {
                this.statusTarget.textContent = 'No se ha podido guardar. Reintentando…';
                this.statusTarget.classList.remove('canvas-status-saved');
            }
            setTimeout(() => this._save(), 2000);
        }
    }

    _renderStatus() {
        if (!this.hasStatusTarget) return;
        if (!this._lastSavedAt) {
            this.statusTarget.textContent = '';
            this.statusTarget.classList.remove('canvas-status-saved');
            return;
        }
        const seconds = Math.max(0, Math.round((Date.now() - this._lastSavedAt.getTime()) / 1000));
        const label = seconds < 5
            ? 'Guardado'
            : seconds < 60
                ? `Guardado hace ${seconds}s`
                : `Guardado hace ${Math.round(seconds / 60)} min`;
        this.statusTarget.textContent = label;
        this.statusTarget.classList.add('canvas-status-saved');
    }

    _scheduleStatusTick() {
        if (this._tickTimer) clearInterval(this._tickTimer);
        this._tickTimer = setInterval(() => this._renderStatus(), 5000);
    }

    _currentPayload() {
        const payload = {
            title: this.hasTitleTarget ? this.titleTarget.value : '',
        };
        if (this.hasBodyTarget) payload.description = this.bodyTarget.value;
        if (this.hasExponeTarget) payload.expone = this.exponeTarget.value;
        if (this.hasSolicitaTarget) payload.solicita = this.solicitaTarget.value;
        return payload;
    }

    _payloadEquals(a, b) {
        if (!a || !b) return false;
        return a.title === b.title
            && (a.description ?? '') === (b.description ?? '')
            && (a.expone ?? '') === (b.expone ?? '')
            && (a.solicita ?? '') === (b.solicita ?? '');
    }

    _htmlToPlain(html) {
        if (!html) return '';
        const tmp = document.createElement('div');
        tmp.innerHTML = String(html).replace(/<\/p>/gi, '\n\n').replace(/<br\s*\/?>/gi, '\n');
        return (tmp.textContent || '').trim();
    }

    /** Public: forces an immediate save and resolves when persisted. */
    async flush() {
        if (this._saveTimer) {
            clearTimeout(this._saveTimer);
            this._saveTimer = null;
        }
        await this._save();
    }

    /**
     * Click handler for the "Descargar PDF" CTA. Flushes any pending autosave
     * so the PDF is rendered against the very latest title/description, then
     * navigates the browser to the PDF URL (which streams as an attachment).
     */
    async downloadPdf(event) {
        event?.preventDefault();
        const link = event?.currentTarget;
        const url = link?.dataset?.realizarCanvasPdfUrlParam || link?.getAttribute('href');
        if (!url) return;
        await this.flush();
        window.location.href = url;
    }

    /**
     * Submit handler for the "Enviar al organismo" form. Prevents the race
     * where the user clicks dispatch before autosave/onDecision have flushed
     * (we'd POST to the dispatch endpoint while the row still has empty
     * title/expone/solicita and possibly a stale regDestination snapshot,
     * which surfaces in production as `reg_body_required` /
     * `reg_destination_required` blockers despite the canvas looking full).
     *
     * Order: confirm → block if chat is mid-stream → flush autosave → submit.
     */
    async dispatchSubmit(event) {
        event?.preventDefault();
        const form = event?.currentTarget;
        if (!form) return;

        const button = form.querySelector('button[type="submit"]');
        if (button?.dataset.dispatchInFlight === '1') return;

        if (!window.confirm('¿Enviar la solicitud al organismo? El agente local empezará a procesarla y no podrás editarla más.')) {
            return;
        }

        // If the assistant chat is mid-turn, the server hasn't persisted the
        // generated draft yet — wait for it instead of dispatching empty.
        const chatEl = document.querySelector('[data-controller~="assistant-chat"]');
        const chatCtrl = chatEl
            ? this.application?.getControllerForElementAndIdentifier(chatEl, 'assistant-chat')
            : null;
        if (chatCtrl && typeof chatCtrl.isBusy === 'function' && chatCtrl.isBusy()) {
            window.alert('El asistente todavía está generando la respuesta. Espera a que termine y vuelve a pulsar "Enviar".');
            return;
        }

        if (button) {
            button.disabled = true;
            button.dataset.dispatchInFlight = '1';
        }

        try {
            await this.flush();
        } catch (err) {
            console.error('flush before dispatch failed', err);
            // Still submit — we'll also pin the current canvas state into the
            // form below so the server has the authoritative content.
        }

        // Atomic canvas snapshot in the same POST: even if autosave didn't
        // land, the dispatch endpoint now writes whatever we send here as the
        // source of truth before running its preflight. Removes the race
        // entirely (we kept hitting REG blockers in prod on rows that looked
        // populated in psql — debouncing was lying to us).
        this._pinCanvasSnapshotToForm(form);

        await this._submitDispatch(form, button);
    }

    /**
     * Sends the dispatch form via fetch so we can intercept JSON responses
     * (including the 409 uncertain_needs_confirmation flow) without a full
     * page reload. On success, redirects to the URL the server returns.
     *
     * @param {HTMLFormElement} form
     * @param {HTMLButtonElement|null} button
     */
    async _submitDispatch(form, button) {
        const restoreButton = () => {
            if (button) {
                button.disabled = false;
                button.dataset.dispatchInFlight = '';
            }
        };

        const body = new FormData(form);
        const url = form.action;

        let response;
        try {
            response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
                body,
            });
        } catch (err) {
            console.error('dispatch fetch failed', err);
            restoreButton();
            window.alert('No se ha podido enviar la solicitud. Comprueba tu conexión y vuelve a intentarlo.');
            return;
        }

        let json = null;
        try {
            json = await response.json();
        } catch (_) {
            // Non-JSON response (e.g. redirect from server for profile gate).
            // Fall back: if 3xx it won't reach here; if the server returned
            // a redirect as a non-JSON 302, fetch follows it — treat non-JSON
            // success as "go home".
        }

        if (response.status === 409 && json?.error === 'uncertain_needs_confirmation') {
            const msg = (json.message || 'Una o más solicitudes podrían haberse presentado ya.')
                + '\n\n¿Reenviar de todas formas?';
            const confirmed = window.confirm(msg);
            if (!confirmed) {
                restoreButton();
                return;
            }
            // Retry with confirmation flag.
            body.append('confirmUncertain', '1');
            let retryResponse;
            try {
                retryResponse = await fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' },
                    body,
                });
            } catch (err) {
                console.error('dispatch retry fetch failed', err);
                restoreButton();
                window.alert('No se ha podido reenviar la solicitud. Comprueba tu conexión y vuelve a intentarlo.');
                return;
            }
            let retryJson = null;
            try { retryJson = await retryResponse.json(); } catch (_) {}
            response = retryResponse;
            json = retryJson;
        }

        // The server may answer with a 302 (e.g. the REG profile gate
        // redirects to the personal-data form). fetch follows it silently;
        // honour wherever it landed instead of dropping the user on home.
        if (response.redirected && response.url) {
            window.location.href = response.url;
            return;
        }

        if (response.ok && json?.redirectUrl) {
            if (Array.isArray(json.tasks) && json.tasks.length > 0 && window.Alpine?.store) {
                // Re-enable the submit button: the modal is store-driven and the
                // user may close it to stay on this page, so the dispatch CTA
                // must not be left permanently disabled.
                restoreButton();
                window.Alpine.store('agentPresent').track(
                    json.tasks.map(t => ({ id: t.taskId, statusUrl: t.statusUrl, bodyName: t.bodyName })),
                    { entityLabel: 'solicitud', doneHref: json.redirectUrl },
                );
                return;
            }
            window.location.href = json.redirectUrl;
            return;
        }

        if (!response.ok) {
            console.error('dispatch error', response.status, json);
            restoreButton();
            const errMsg = json?.error ? `Error: ${json.error}` : `Error HTTP ${response.status}`;
            window.alert(`No se ha podido enviar la solicitud. ${errMsg}`);
            return;
        }

        // Fallback: server returned ok without a redirectUrl (shouldn't happen).
        window.location.href = '/';
    }

    /**
     * Injects hidden inputs into the dispatch form carrying the current
     * canvas content keyed by AccessRequest id, so the dispatch controller
     * writes them atomically before its preflight runs.
     *
     * Form name shape: drafts[<UUID>][title|expone|solicita|description]
     */
    _pinCanvasSnapshotToForm(form) {
        const arId = this._accessRequestIdFromForm(form);
        if (!arId) return;

        const payload = this._currentPayload();
        const inject = (field, value) => {
            if (value === undefined || value === null) return;
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = `drafts[${arId}][${field}]`;
            input.value = String(value);
            form.appendChild(input);
        };
        inject('title', payload.title ?? '');
        if (payload.expone !== undefined) inject('expone', payload.expone);
        if (payload.solicita !== undefined) inject('solicita', payload.solicita);
        if (payload.description !== undefined) inject('description', payload.description);
    }

    /**
     * Pulls the AR UUID from the dispatch form's action URL. The route is
     * /nueva/realizar/enviar/{batchId} but we keyed snapshots by AR id (not
     * batch), so derive AR from the canvas controller's own autosave URL —
     * which IS /nueva/realizar/redactar/{id}/autoguardar.
     */
    _accessRequestIdFromForm(/* form */) {
        if (!this.hasAutosaveUrlValue) return null;
        const m = this.autosaveUrlValue.match(/\/redactar\/([0-9a-fA-F-]{36})\//);
        return m ? m[1] : null;
    }
}
