import { Controller } from '@hotwired/stimulus';

/**
 * La "hoja de papel": el borrador como documento editable dentro de la
 * conversación del asistente. Sustituye a realizar-canvas y complaint-canvas
 * con una única superficie, parametrizada por `shape`:
 *
 *   - shape=portal   → textareas asunto + descripción (autosave debounced).
 *   - shape=reg      → textareas asunto + EXPONE + SOLICITA (autosave).
 *   - shape=complaint→ un textarea de cuerpo (reclamación/alegaciones/consulta);
 *                      guardado explícito fuera. El usuario edita TEXTO PLANO
 *                      igual que la solicitud; el HTML solo se sintetiza en
 *                      `getHtml()` para los endpoints de guardado/PDF/presentar.
 *
 * Comportamiento común:
 *   - Evento `paper-sheet:settled` (600 ms sin cambios) para que la tarjeta
 *     RAG refresque resoluciones similares y probabilidad.
 *   - Método público `replaceContent(payload)` con animación de máquina de
 *     escribir en los textareas. Escape salta al final.
 *   - Indicador de estado en el pie de la hoja.
 *
 * Targets: title, body, expone, solicita, htmlInput (prefill), status
 * Values:  shape, autosaveUrl
 */
export default class extends Controller {
    static targets = ['title', 'body', 'expone', 'solicita', 'editor', 'htmlInput', 'status', 'sources', 'sourcesPills', 'docTypeSelect'];
    static values = {
        shape: { type: String, default: 'portal' },
        autosaveUrl: { type: String, default: '' },
        // shape=complaint: explicit persistence + agent filing endpoints.
        saveUrl: { type: String, default: '' },
        pdfUrl: { type: String, default: '' },
        presentUrl: { type: String, default: '' },
        mode: { type: String, default: '' },
        draftId: { type: String, default: '' },
        // flow=consult: "Guardar en el expediente" endpoint.
        saveDossierUrl: { type: String, default: '' },
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
        // Reclamación/consulta reabierta: el borrador guardado es HTML; se muestra
        // como texto plano para editar (el HTML se re-sintetiza al guardar/PDF).
        if (this._isHtmlDoc() && this.hasHtmlInputTarget && this.hasBodyTarget && this.htmlInputTarget.value) {
            this.bodyTarget.value = this._htmlToPlain(this.htmlInputTarget.value);
            requestAnimationFrame(() => this._growBody(this.bodyTarget));
        }
        document.addEventListener('keydown', this._onKeyDown);

        this._renderStatus();
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

    /**
     * shape=complaint marca los flujos cuyo documento se GUARDA como HTML
     * (reclamación/alegaciones/consulta), pero el editor es un textarea de texto
     * plano igual que la solicitud: el HTML se sintetiza en `getHtml()` solo al
     * guardar/exportar. No hay editor enriquecido (Trix retirado).
     */
    _isHtmlDoc() {
        return this.shapeValue === 'complaint';
    }

    /**
     * Public: current draft as HTML. El usuario edita texto plano en el textarea;
     * aquí lo envolvemos en párrafos para los endpoints (guardar/PDF/presentar)
     * que esperan HTML, sin cambiar el backend.
     */
    getHtml() {
        if (this.hasBodyTarget) return this._plainToHtml(this.bodyTarget.value);
        if (this.hasHtmlInputTarget) return this.htmlInputTarget.value || '';
        return '';
    }

    /** Public: plain-text length of the draft, for counters. */
    charCount() {
        return this._bodyTargets().reduce((acc, el) => acc + el.value.length, 0);
    }

    isReady() {
        return true;
    }

    /* ── Textareas (shape=portal|reg) ───────────────────────────────────── */

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

    _onInput() {
        if (this._frozen) return;   // instantánea de solo lectura: no autosave/settle
        if (this._typing) return; // suppress autosave/settle while we're typing programmatically

        this._markDirty();

        if (this._hasAutosave()) {
            if (this._saveTimer) clearTimeout(this._saveTimer);
            this._saveTimer = setTimeout(() => this._save(), 800);
        }

        if (this._settleTimer) clearTimeout(this._settleTimer);
        this._settleTimer = setTimeout(() => {
            this.dispatch('settled', { detail: this._currentPayload() });
        }, 600);
    }

    _hasAutosave() {
        return !this._isHtmlDoc() && this.autosaveUrlValue !== '';
    }

    _markDirty() {
        if (!this.hasStatusTarget) return;
        this.statusTarget.textContent = this._hasAutosave()
            ? 'Cambios sin guardar…'
            : 'Cambios sin guardar — usa «Guardar borrador»';
        this.statusTarget.classList.remove('canvas-status-saved');
        this.dispatch('dirty');
    }

    _onKeyDown(event) {
        if (event.key === 'Escape' && this._typing && this._typingAbort) {
            this._typingAbort();
        }
    }

    _reduceMotion() {
        return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    /**
     * Public — freeze this sheet into a read-only snapshot. The chat controller
     * calls it on the previous latest sheet right before appending a new one, so
     * each generation/rewrite becomes its own document in the stream and older
     * versions can't be edited, autosaved or submitted.
     */
    freeze() {
        if (this._frozen) return;
        this._frozen = true;
        if (this._saveTimer) clearTimeout(this._saveTimer);
        if (this._settleTimer) clearTimeout(this._settleTimer);

        const fields = [];
        if (this.hasTitleTarget) fields.push(this.titleTarget);
        fields.push(...this._bodyTargets());
        fields.forEach((el) => { el.readOnly = true; el.setAttribute('tabindex', '-1'); });

        this.element.classList.add('is-frozen');
    }

    /**
     * Render the «Fuentes citadas» pills inside this sheet. `sources` are the
     * server-resolved citations ({type, reference, label, url}); resolution →
     * ficha interna (sin glifo externo), criterio/sentencia → documento externo.
     *
     * @param {Array<{type?: string, reference?: string, label?: string, url?: string|null}>} sources
     */
    _renderSources(sources) {
        if (!this.hasSourcesTarget || !this.hasSourcesPillsTarget) return;

        const list = Array.isArray(sources)
            ? sources.filter((s) => s && (s.label || s.reference))
            : [];

        if (list.length === 0) {
            this.sourcesPillsTarget.innerHTML = '';
            this.sourcesTarget.hidden = true;
            return;
        }

        const icons = { resolution: 'scale', criterion: 'book-open', judgment: 'gavel' };
        this.sourcesPillsTarget.innerHTML = list.map((s) => {
            const label = this._escapeHtml(s.label || s.reference || '');
            const icon = icons[s.type] || 'file-text';
            const internal = s.type === 'resolution';
            if (s.url) {
                const cls = internal ? 'draft-source draft-source--interna' : 'draft-source';
                // Todas las fuentes abren en nueva pestaña (no perder el borrador).
                const ext = internal ? '' : ' <i data-lucide="external-link" class="draft-source-ext"></i>';
                return `<a href="${this._escapeHtml(s.url)}" class="${cls}" target="_blank" rel="noopener noreferrer"><i data-lucide="${icon}"></i> ${label}${ext}</a>`;
            }
            return `<span class="draft-source"><i data-lucide="${icon}"></i> ${label}</span>`;
        }).join('');

        this.sourcesTarget.hidden = false;
        if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
    }

    _escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
        }[c]));
    }

    /* ── Escritura programática (el asistente entrega un borrador) ─────── */

    /**
     * Public — invoked by the chat controller after the assistant returns a
     * draft. Textareas get a typewriter animation (autosave suspended, one
     * flush at the end); Trix gets loadHTML. Pressing Escape jumps to the end.
     *
     * @param {{title?: string, bodyHtml?: string, expone?: string, solicita?: string}} payload
     */
    async replaceContent({ title = '', bodyHtml = '', expone = '', solicita = '', sources = [] } = {}) {
        // Todos los flujos editan TEXTO PLANO en textareas (estilo solicitud);
        // el HTML de reclamación/consulta se sintetiza solo en el borde de
        // guardado/PDF (getHtml → _plainToHtml). El cuerpo entrante se pasa a
        // texto plano y se teclea en el textarea `body`.
        const bodyCap = this._isHtmlDoc() ? 12000 : 3000;
        const plainBody = this._htmlToPlain(bodyHtml).slice(0, bodyCap);
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
        lockTargets.push(...this._bodyTargets());
        lockTargets.forEach((el) => {
            el.readOnly = true;
            el.value = '';
            el.classList.add('canvas-typing');
        });

        let aborted = false;
        let resolveTyping;
        const abortPromise = new Promise((resolve) => {
            resolveTyping = resolve;
            this._typingAbort = () => { aborted = true; resolve(); };
        });

        try {
            // La caja «se genera»: punto → línea → rectángulo, y luego se teclea.
            this.element.classList.add('is-gen');
            if (!this._reduceMotion()) {
                await new Promise((resolve) => setTimeout(resolve, 820));
            }

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
                // Cada tick reescribe TODO el value y hace crecer el textarea (un
                // reflow); demasiados ticks en un borrador largo escalan
                // cuadráticamente. Acotamos a ~50 ticks → ~1 s sea cual sea la
                // longitud, sin el efecto "se atasca" que se veía en EXPONE largos.
                const ticks = Math.min(50, Math.max(10, Math.ceil(text.length / 40)));
                const intervalMs = 20;
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

        this._renderSources(sources);

        // One save at the end, plus one settled-event so the RAG card refreshes.
        if (this._hasAutosave()) await this._save();
        this.dispatch('settled', { detail: this._currentPayload() });

        // Resolve the abort promise if we finished naturally (so any awaiters unblock).
        // (No-op if already resolved by Esc.)
        resolveTyping?.();
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

    /* ── Autosave (shape=portal|reg) ────────────────────────────────────── */

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

    /** Public: lets the header action bar reflect an explicit save (complaint). */
    markSaved() {
        this._lastSavedAt = new Date();
        this._renderStatus();
        this._scheduleStatusTick();
    }

    _scheduleStatusTick() {
        if (this._tickTimer) clearInterval(this._tickTimer);
        this._tickTimer = setInterval(() => this._renderStatus(), 5000);
    }

    _currentPayload() {
        if (this._isHtmlDoc()) {
            return { bodyHtml: this.getHtml() };
        }
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
        const withBreaks = String(html)
            .replace(/<\/(p|div|h[1-6]|li|tr)>/gi, '\n\n')
            .replace(/<br\s*\/?>/gi, '\n');
        const tmp = document.createElement('div');
        tmp.innerHTML = withBreaks;
        return (tmp.textContent || '').replace(/\n{3,}/g, '\n\n').trim();
    }

    /**
     * Inverse of {@see _htmlToPlain}: wraps the plain-text the user edited into
     * simple HTML (one <p> per blank-line-separated block, single newlines as
     * <br>) so the save/PDF endpoints keep receiving HTML. The user never sees
     * HTML — this is only the boundary format.
     */
    _plainToHtml(text) {
        const t = String(text ?? '').trim();
        if (t === '') return '';
        return t.split(/\n{2,}/)
            .map((block) => '<p>' + this._escapeHtml(block.trim()).replace(/\n/g, '<br>') + '</p>')
            .join('');
    }

    /** Public: forces an immediate save and resolves when persisted. */
    async flush() {
        if (!this._hasAutosave()) return;
        if (this._saveTimer) {
            clearTimeout(this._saveTimer);
            this._saveTimer = null;
        }
        await this._save();
    }

    /**
     * Click handler for the "Descargar PDF" CTA. Portal/reg: flushes any
     * pending autosave so the PDF is rendered against the very latest
     * title/description, then navigates to the PDF URL (streams as an
     * attachment). Complaint: POSTs the current HTML and downloads the blob
     * (the canvas is ephemeral until "Guardar", so the server can't render
     * from the entity).
     */
    async downloadPdf(event) {
        event?.preventDefault();
        if (this._isHtmlDoc()) {
            await this._downloadComplaintPdf(event?.currentTarget);
            return;
        }
        const link = event?.currentTarget;
        const url = link?.dataset?.paperSheetPdfUrlParam || link?.getAttribute('href');
        if (!url) return;
        await this.flush();
        window.location.href = url;
    }

    /* ── Acciones explícitas (shape=complaint) ─────────────────────────── */

    /** "Guardar borrador": persists the Trix HTML as a Document. */
    async saveDraft(event) {
        event?.preventDefault();
        if (!this.saveUrlValue || !this.isReady()) return false;
        const html = this.getHtml();
        if (!html.trim()) {
            window.alert('No hay contenido para guardar.');
            return false;
        }
        const button = event?.currentTarget;
        if (button) button.disabled = true;
        try {
            const res = await fetch(this.saveUrlValue, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ mode: this.modeValue, html, draftId: this.draftIdValue || null }),
            });
            const data = await res.json();
            if (!res.ok || !data.success) {
                window.alert(data.error || 'No se pudo guardar el borrador.');
                return false;
            }
            const firstSave = data.documentId && this.draftIdValue !== data.documentId;
            if (data.documentId) this.draftIdValue = data.documentId;
            if (data.redirectUrl && firstSave) {
                // First save: reflect the saved draft id in the URL, keeping
                // the ui flag so a reload stays on the same interface.
                let target = data.redirectUrl;
                const ui = new URLSearchParams(window.location.search).get('ui');
                if (ui) {
                    const url = new URL(target, window.location.origin);
                    url.searchParams.set('ui', ui);
                    target = url.pathname + url.search;
                }
                window.history.replaceState(null, '', target);
            }
            this.markSaved();
            return true;
        } catch (e) {
            window.alert('Error de red al guardar el borrador.');
            return false;
        } finally {
            if (button) button.disabled = false;
        }
    }

    async _downloadComplaintPdf(button) {
        if (!this.pdfUrlValue || !this.isReady()) return;
        if (button) button.disabled = true;
        try {
            const res = await fetch(this.pdfUrlValue, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ html: this.getHtml() }),
            });
            if (!res.ok) {
                const j = await res.json().catch(() => ({}));
                window.alert(j.error || 'Error al generar PDF');
                return;
            }
            const blob = await res.blob();
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = res.headers.get('content-disposition')?.match(/filename="(.+)"/)?.[1] || 'documento.pdf';
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
        } catch (e) {
            window.alert('Error de conexión.');
        } finally {
            if (button) button.disabled = false;
        }
    }

    /**
     * flow=consult: persist the current document into the expediente. Reads the
     * doc-type from the footer select (the agent pre-selected it). The dedicated
     * types (reclamación / respuesta a alegaciones) route server-side into their
     * official draft and may ask to confirm before overwriting an existing one.
     */
    async saveToDossier(event) {
        event?.preventDefault();
        if (!this.saveDossierUrlValue || !this.isReady()) return;
        const html = this.getHtml();
        if (!html.trim()) { window.alert('No hay documento que guardar.'); return; }
        const docType = this.hasDocTypeSelectTarget ? this.docTypeSelectTarget.value : 'other';
        // Consult sheets have no title field; fall back to the agent's title
        // stashed on the element by assistant-chat#_applyDraftToSheet.
        const title = (this.hasTitleTarget ? this.titleTarget.value : '') || this.element.dataset.docTitle || '';
        const button = event?.currentTarget;
        if (button) button.disabled = true;
        try {
            const post = (overwrite) => fetch(this.saveDossierUrlValue, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ html, docType, title, overwrite }),
            });
            let res = await post(false);
            let data = await res.json().catch(() => ({}));
            if (res.ok && data.needsConfirm) {
                if (!window.confirm(data.message || 'Ya existe un borrador de este tipo. ¿Quieres sustituirlo?')) return;
                res = await post(true);
                data = await res.json().catch(() => ({}));
            }
            if (!res.ok || !data.success) {
                window.alert(data.error || 'No se pudo guardar el documento.');
                return;
            }
            if (this.hasStatusTarget) {
                this.statusTarget.textContent = 'Guardado en el expediente ✓';
                this.statusTarget.classList.add('canvas-status-saved');
            }
            this.dispatch('doc-saved', { detail: { documentId: data.documentId } });
            // Best-effort live refresh of the dossier's documents list (the
            // container carries its own fragment URL in data-documents-url).
            const list = document.getElementById('documents-list');
            const fragUrl = list?.dataset?.documentsUrl;
            if (list && fragUrl) {
                try {
                    const frag = await fetch(fragUrl, { credentials: 'same-origin' });
                    if (frag.ok) {
                        list.innerHTML = await frag.text();
                        if (window.lucide) window.lucide.createIcons();
                    }
                } catch (_e) { /* refresh is best-effort */ }
            }
        } catch (e) {
            window.alert('Error de red al guardar el documento.');
        } finally {
            if (button) button.disabled = false;
        }
    }

    /**
     * "Presentar (supervisado|auto)": saves first if there are unsaved
     * changes, then queues the agent task through the shared Alpine store
     * (the modal partial is global on the page).
     */
    async presentViaAgent(event) {
        event?.preventDefault();
        if (!this.presentUrlValue) return;
        const agentMode = event?.currentTarget?.dataset?.paperSheetAgentModeParam || 'supervised';
        const store = window.Alpine?.store ? window.Alpine.store('agentPresent') : null;
        if (!store || store.busy) return;

        const saved = await this.saveDraft();
        if (!saved) return;

        store.open('reclamación');
        try {
            const res = await fetch(this.presentUrlValue, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ mode: agentMode }),
            });
            const data = await res.json();
            if (!res.ok) {
                store.fail(data.error || 'No se pudo encolar la tarea.');
                return;
            }
            store.track(
                [{ id: data.taskId, statusUrl: data.statusUrl, schemeUrl: data.schemeUrl }],
                { entityLabel: 'reclamación' },
            );
        } catch (e) {
            store.fail('Error de red.');
        }
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
     * batch), so derive AR from the sheet's own autosave URL — which IS
     * /nueva/realizar/redactar/{id}/autoguardar.
     */
    _accessRequestIdFromForm(/* form */) {
        if (!this.hasAutosaveUrlValue || this.autosaveUrlValue === '') return null;
        const m = this.autosaveUrlValue.match(/\/redactar\/([0-9a-fA-F-]{36})\//);
        return m ? m[1] : null;
    }
}
