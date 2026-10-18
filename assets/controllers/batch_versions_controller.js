import { Controller } from '@hotwired/stimulus';

/**
 * Unified template of a multi-recipient batch ("plantilla común").
 *
 * Mounted on the conversation root next to `assistant-chat` whenever the page
 * belongs to a batch with several recipients:
 *
 * - Template page: keeps the «partes variables» legend under the latest sheet
 *   in sync with the text, and generates the per-organism versions — one POST
 *   per recipient (two at a time) so each row of «Versiones por organismo»
 *   shows its own progress. Versions the user adjusted (or wrote without the
 *   template) are skipped unless the overwrite checkbox is ticked.
 * - Version page: «Regenerar desde la plantilla» / «Generar desde la plantilla
 *   común» (regenerateOne) and a reload to show the new text and thread turn.
 *
 * The states come from the server (DraftBatchOverview); this controller only
 * reflects the result of each generation, never re-derives them.
 *
 * Each version that comes out of a generation is added to the chat timeline,
 * under the template, as a chip linking to it (the «timeline» target, rendered
 * server-side on reload by asistente/_versions_event.html.twig — keep both in sync).
 *
 * Targets: row, overwrite, summary, markers, generateButton, timeline
 */
const KNOWN_MARKERS = {
    ORGANISMO: 'nombre del organismo',
    UNIDAD: 'unidad de destino',
    LEY_APLICABLE: 'ley aplicable de cada uno',
};
const MARKER_RE = /\[\[\s*([A-Z_ÁÉÍÓÚÑ]+)\s*\]\]/g;
const STATE_LABELS = {
    template: 'Desde la plantilla',
    adjusted: 'Ajustada a mano',
    independent: 'Redactada aparte',
    empty: 'Sin redactar',
    sent: 'Enviada',
};
const CONCURRENCY = 2;

export default class extends Controller {
    static targets = ['row', 'overwrite', 'summary', 'markers', 'generateButton', 'timeline'];

    connect() {
        this._running = false;
        this._onSettled = () => this._renderMarkers();
        window.addEventListener('paper-sheet:settled', this._onSettled);
        this.element.addEventListener('input', this._onSettled);
        requestAnimationFrame(() => this._renderMarkers());
    }

    disconnect() {
        window.removeEventListener('paper-sheet:settled', this._onSettled);
        this.element.removeEventListener('input', this._onSettled);
    }

    /* ── Markers legend ─────────────────────────────────────────────── */

    markersTargetConnected() {
        this._renderMarkers();
    }

    _latestSheet() {
        const sheets = this.element.querySelectorAll('.paper-sheet--template');
        return sheets.length ? sheets[sheets.length - 1] : null;
    }

    _renderMarkers() {
        const sheet = this._latestSheet();
        if (!sheet) return;
        const legend = sheet.querySelector('[data-batch-versions-target~="markers"]');
        if (!legend) return;

        const text = Array.from(sheet.querySelectorAll('textarea')).map((el) => el.value).join('\n');
        if (text.trim() === '') {
            legend.innerHTML = '';
            return;
        }

        const counts = new Map();
        for (const m of text.matchAll(MARKER_RE)) counts.set(m[1], (counts.get(m[1]) || 0) + 1);

        if (counts.size === 0) {
            legend.innerHTML = `<p class="template-markers-empty">Sin partes variables: el texto será igual para todos los organismos. Al generar, la referencia legal se adapta igualmente a la ley de cada uno. Si quieres nombrar al organismo, escribe <span class="marker-badge marker-badge--organismo">[[ORGANISMO]]</span>.</p>`;
            return;
        }

        const chips = Array.from(counts.entries()).map(([name, count]) => {
            const known = Object.prototype.hasOwnProperty.call(KNOWN_MARKERS, name);
            const hint = known ? KNOWN_MARKERS[name] : 'marcador desconocido: no se sustituirá';
            const variant = known ? name.toLowerCase().replace(/_/g, '-') : 'unknown';
            return `<span class="template-marker" title="${this._escape(hint)}"><span class="marker-badge marker-badge--${variant}">[[${this._escape(name)}]]</span> ×${count} <span>${this._escape(hint)}</span></span>`;
        }).join('');
        legend.innerHTML = `<span class="template-markers-label">Partes variables</span>${chips}`;
    }

    /* ── Template saved after versions exist → mark them as outdated ── */

    onTemplateSaved(event) {
        // Only the template page has rows; a version's own sheet saving is not a template change.
        if (!this.hasRowTarget || !event.target.closest('.paper-sheet--template')) return;
        for (const row of this.rowTargets) {
            if (['template', 'adjusted'].includes(row.dataset.state)) this._markOutdated(row);
        }
    }

    _markOutdated(row) {
        row.dataset.outdated = 'true';
        const chip = this._currentTimeline()?.querySelector(`[data-version-chip="${row.dataset.id}"]`);
        if (chip) {
            chip.className = 'version-chip version-chip--outdated';
            chip.querySelector('.version-chip-state').textContent = 'plantilla cambiada';
        }
        if (!row.querySelector('[data-role="outdated"]')) {
            const flag = document.createElement('span');
            flag.className = 'batch-flag batch-flag--warn';
            flag.dataset.role = 'outdated';
            flag.textContent = 'plantilla cambiada';
            row.querySelector('.batch-version-meta')?.insertBefore(flag, row.querySelector('.batch-version-channel'));
        }
        const tab = document.querySelector(`[data-batch-tab="${row.dataset.id}"]`);
        if (tab && !tab.querySelector('[data-batch-tab-outdated]')) {
            const flag = document.createElement('span');
            flag.className = 'batch-flag batch-flag--warn';
            flag.dataset.batchTabOutdated = '';
            flag.textContent = 'plantilla cambiada';
            tab.appendChild(flag);
        }
    }

    /* ── Generation (template page) ─────────────────────────────────── */

    async generate(event) {
        event?.preventDefault();
        if (this._running) return;

        if (this._chatBusy()) {
            this._summary('El asistente todavía está escribiendo la plantilla. Espera a que termine y vuelve a pulsar.', 'warn');
            return;
        }

        const sheet = this._latestSheet();
        const text = sheet ? Array.from(sheet.querySelectorAll('textarea')).map((el) => el.value).join('').trim() : '';
        if (text === '') {
            this._summary('Primero redacta la plantilla común (o pídesela al asistente): las versiones se generan a partir de ella.', 'warn');
            return;
        }

        // Persist the very latest template before the server reads it.
        await this._flushSheet(sheet);

        const overwrite = this.hasOverwriteTarget && this.overwriteTarget.checked;
        const rows = this.rowTargets.filter((row) => {
            const { state, outdated } = row.dataset;
            if (state === 'sent') return false;
            if (state === 'adjusted' || state === 'independent') return overwrite;
            if (state === 'template') return outdated === 'true';
            return true; // empty
        });

        if (rows.length === 0) {
            const skipped = this.rowTargets.filter((r) => ['adjusted', 'independent'].includes(r.dataset.state)).length;
            this._summary(skipped > 0
                ? 'Las versiones generadas están al día. Para rehacer las que ajustaste a mano, marca la casilla de regenerarlas.'
                : 'Todas las versiones están al día con la plantilla.', 'ok');
            return;
        }

        this._running = true;
        this._setButtonsBusy(true, rows.length);
        this._summary(`Generando ${rows.length} ${rows.length === 1 ? 'versión' : 'versiones'}… Puedes seguir leyendo; no cierres esta pestaña hasta que termine.`, 'busy');

        this._timelineStart(rows);

        const results = [];
        const queue = rows.slice();
        const worker = async () => {
            while (queue.length) {
                const row = queue.shift();
                results.push(await this._generateRow(row, overwrite));
            }
        };
        try {
            await Promise.all(Array.from({ length: Math.min(CONCURRENCY, rows.length) }, worker));
        } finally {
            this._running = false;
            this._setButtonsBusy(false);
            this._timelineFinish();
        }

        const ok = results.filter((r) => r.ok);
        const failed = results.filter((r) => !r.ok);
        const fallback = ok.filter((r) => r.fallback);
        const parts = [];
        if (ok.length) parts.push(`${ok.length} ${ok.length === 1 ? 'versión generada' : 'versiones generadas'}. Ábrelas desde las pestañas de arriba para revisarlas o ajustarlas: cada una tiene su propia conversación.`);
        if (fallback.length) parts.push(`En ${fallback.length} solo se han sustituido los marcadores (el asistente no respondió): revísalas con calma.`);
        if (failed.length) parts.push(`No se ${failed.length === 1 ? 'ha podido generar 1' : `han podido generar ${failed.length}`}: vuelve a pulsar para reintentarlo.`);
        this._summary(parts.join(' '), failed.length ? 'warn' : 'ok', ok[0]?.url);
    }

    async _generateRow(row, overwrite) {
        this._setRowState(row, 'busy', 'Generando…');
        try {
            const response = await fetch(row.dataset.generateUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ overwrite }),
            });
            const json = await response.json().catch(() => ({}));
            if (!response.ok) {
                const msg = json.error === 'would_overwrite' ? 'Ajustada: no se toca'
                    : json.error === 'already_sent' ? 'Ya enviada'
                        : 'Error al generar';
                this._setRowState(row, 'error', msg);
                this._failVersionChip(row, msg);
                return { ok: false };
            }
            row.dataset.state = json.state;
            row.dataset.outdated = 'false';
            row.querySelector('[data-role="outdated"]')?.remove();
            this._setRowState(row, json.fallback ? 'warn' : 'ok', json.fallback ? 'Revisar: sin adaptar' : (STATE_LABELS[json.state] || json.label));
            this._updateTab(row.dataset.id, json);
            this._addVersionChip(row, json);
            return { ok: true, fallback: !!json.fallback, url: json.url };
        } catch (e) {
            console.error('batch version generation failed', e);
            this._setRowState(row, 'error', 'Error de conexión');
            this._failVersionChip(row, 'error de conexión');
            return { ok: false };
        }
    }

    /* ── Timeline: one chip per generated version ───────────────────── */

    /** The milestone in the page, if any. Stimulus registers targets asynchronously, so a node just appended is tracked by reference. */
    _currentTimeline() {
        if (this.hasTimelineTarget) return this.timelineTarget;
        return this._timeline?.isConnected ? this._timeline : null;
    }

    _timelineEl() {
        const existing = this._currentTimeline();
        if (existing) return existing;
        if (this._timeline) return this._timeline;
        const node = document.createElement('div');
        node.className = 'chat-event chat-event--versions';
        node.dataset.batchVersionsTarget = 'timeline';
        node.innerHTML = '<span class="chat-event-text" data-role="title"></span><div class="version-chips" data-role="chips"></div>';
        this._timeline = node;
        return node;
    }

    /** Chip of one recipient inside the milestone (created on demand). */
    _versionChip(row) {
        const chips = this._timelineEl().querySelector('[data-role="chips"]');
        let chip = chips.querySelector(`[data-version-chip="${row.dataset.id}"]`);
        if (!chip) {
            chip = document.createElement('a');
            chip.dataset.versionChip = row.dataset.id;
            chip.innerHTML = '<span class="version-chip-dot" aria-hidden="true"></span><span class="version-chip-name"></span><span class="version-chip-state"></span>';
            const name = row.dataset.name || '';
            chip.querySelector('.version-chip-name').textContent = name.length > 36 ? `${name.slice(0, 35)}…` : name;
            chip.title = `Versión para ${name}`;
            chips.appendChild(chip);
        }
        return chip;
    }

    /** Generation starts: the milestone moves to the end of the thread and shows progress. */
    _timelineStart(rows) {
        const history = this.element.querySelector('[data-assistant-chat-target~="history"]');
        if (!history) return;
        const timeline = this._timelineEl();
        // The milestone always sits at the end of the thread, after the template it came from.
        if (timeline.parentNode !== history || timeline.nextElementSibling !== null) history.appendChild(timeline);

        this._progress = { done: 0, total: rows.length };
        timeline.classList.add('is-generating');
        timeline.setAttribute('aria-busy', 'true');
        if (!timeline.querySelector('[data-role="progress"]')) {
            const bar = document.createElement('div');
            bar.className = 'version-progress';
            bar.dataset.role = 'progress';
            bar.setAttribute('role', 'progressbar');
            bar.setAttribute('aria-valuemin', '0');
            bar.innerHTML = '<span class="version-progress-fill"></span>';
            timeline.insertBefore(bar, timeline.querySelector('[data-role="chips"]'));
        }
        for (const row of rows) {
            const chip = this._versionChip(row);
            chip.className = 'version-chip version-chip--busy';
            chip.removeAttribute('href');
            chip.querySelector('.version-chip-state').textContent = 'generando…';
        }
        this._renderProgress();
        timeline.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    _renderProgress() {
        const timeline = this._currentTimeline();
        if (!timeline || !this._progress) return;
        const { done, total } = this._progress;
        timeline.querySelector('[data-role="title"]').textContent =
            `Generando ${total === 1 ? 'la versión' : 'versiones'} · ${done}/${total}…`;
        const bar = timeline.querySelector('[data-role="progress"]');
        if (bar) {
            bar.setAttribute('aria-valuemax', String(total));
            bar.setAttribute('aria-valuenow', String(done));
            // A sliver from the start so the bar reads as "working" before the first one lands.
            bar.querySelector('.version-progress-fill').style.width = `${Math.max(6, Math.round((done / Math.max(total, 1)) * 100))}%`;
        }
    }

    _tickProgress() {
        if (!this._progress) return;
        this._progress.done++;
        this._renderProgress();
    }

    /** Generation over: drop the progress, keep the chips with their final state. */
    _timelineFinish() {
        const timeline = this._currentTimeline();
        if (!timeline) return;
        timeline.classList.remove('is-generating');
        timeline.removeAttribute('aria-busy');
        timeline.querySelector('[data-role="progress"]')?.remove();
        this._progress = null;
        const count = timeline.querySelectorAll('[data-version-chip]:not(.version-chip--error)').length;
        timeline.querySelector('[data-role="title"]').textContent = `Versiones generadas desde la plantilla · ${count}/${this.rowTargets.length}`;
    }

    _addVersionChip(row, json) {
        const chip = this._versionChip(row);
        const kind = json.fallback ? 'warn' : json.state;
        chip.className = `version-chip version-chip--${kind} is-new`;
        chip.href = json.url;
        chip.title = `Abrir la versión para ${row.dataset.name}`;
        chip.querySelector('.version-chip-state').textContent = json.fallback ? 'revisar: sin adaptar' : (STATE_LABELS[json.state] || json.label);
        this._tickProgress();
    }

    _failVersionChip(row, message) {
        if (!this._currentTimeline()) return;
        const chip = this._versionChip(row);
        chip.className = 'version-chip version-chip--error is-new';
        chip.removeAttribute('href');
        chip.querySelector('.version-chip-state').textContent = message.toLowerCase();
        this._tickProgress();
    }

    _setRowState(row, kind, label) {
        const el = row.querySelector('[data-role="state"]');
        if (!el) return;
        el.className = `batch-version-state batch-version-state--${kind === 'ok' ? row.dataset.state : kind}`;
        el.innerHTML = kind === 'busy' ? `<span class="batch-dot-pulse"></span>${this._escape(label)}` : this._escape(label);
    }

    _updateTab(id, json) {
        const tab = document.querySelector(`[data-batch-tab="${id}"]`);
        if (!tab) return;
        tab.className = tab.className.replace(/batch-tab--[a-z]+/, `batch-tab--${json.state}`);
        const label = tab.querySelector('[data-batch-tab-label]');
        if (label) label.textContent = json.label;
        tab.querySelector('[data-batch-tab-outdated]')?.remove();
    }

    /* ── One version (version page) ─────────────────────────────────── */

    async regenerateOne(event) {
        event?.preventDefault();
        if (this._running) return;
        const button = event?.currentTarget;
        const { url, overwrite } = event?.params || {};
        if (!url) return;

        if (this._chatBusy()) {
            window.alert('El asistente todavía está respondiendo en esta pestaña. Espera a que termine.');
            return;
        }
        if (overwrite && !window.confirm('Se sustituirá el texto de esta versión por la plantilla común adaptada, y perderás los ajustes que hiciste aquí. ¿Continuar?')) {
            return;
        }

        this._running = true;
        if (button) {
            button.disabled = true;
            button.dataset.label = button.innerHTML;
            button.textContent = 'Generando desde la plantilla…';
        }
        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ overwrite: !!overwrite }),
            });
            const json = await response.json().catch(() => ({}));
            if (response.ok) {
                window.location.reload();
                return;
            }
            const msg = json.error === 'empty_template'
                ? 'La plantilla común está vacía. Redáctala primero.'
                : json.error === 'would_overwrite'
                    ? 'Esta versión ya tiene texto propio. Usa «Regenerar» desde la plantilla común si quieres sobrescribirla.'
                    : 'No se ha podido generar la versión. Inténtalo de nuevo.';
            window.alert(msg);
        } catch (e) {
            console.error(e);
            window.alert('No se ha podido contactar con el servidor. Comprueba tu conexión.');
        } finally {
            this._running = false;
            if (button) {
                button.disabled = false;
                button.innerHTML = button.dataset.label || button.innerHTML;
            }
        }
    }

    /* ── Helpers ─────────────────────────────────────────────────────── */

    _chatBusy() {
        const ctrl = this.application.getControllerForElementAndIdentifier(this.element, 'assistant-chat');
        return !!ctrl?.isBusy?.();
    }

    async _flushSheet(sheet) {
        if (!sheet) return;
        const ctrl = this.application.getControllerForElementAndIdentifier(sheet, 'paper-sheet');
        try { await ctrl?.flush?.(); } catch (e) { console.error('template flush failed', e); }
    }

    _setButtonsBusy(busy, count = 0) {
        for (const button of this.generateButtonTargets) {
            if (busy) {
                button.dataset.label = button.innerHTML;
                button.disabled = true;
                button.innerHTML = `<span class="batch-dot-pulse"></span> Generando ${count} ${count === 1 ? 'versión' : 'versiones'}…`;
            } else {
                button.disabled = false;
                if (button.dataset.label) button.innerHTML = button.dataset.label;
            }
        }
        if (!busy && window.lucide?.createIcons) window.lucide.createIcons();
    }

    _summary(text, kind, linkUrl = null) {
        if (!this.hasSummaryTarget) return;
        const el = this.summaryTarget;
        el.hidden = false;
        el.className = `batch-panel-result batch-panel-result--${kind}`;
        el.textContent = text;
        if (linkUrl) {
            const a = document.createElement('a');
            a.href = linkUrl;
            a.className = 'batch-panel-result-link';
            a.textContent = 'Revisar la primera versión →';
            el.appendChild(document.createElement('br'));
            el.appendChild(a);
        }
        el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    _escape(s) {
        return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }
}
