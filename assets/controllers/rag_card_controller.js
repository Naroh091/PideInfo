import { Controller } from '@hotwired/stimulus';

/**
 * Tarjeta "Informe preliminar" dentro de la conversación: probabilidad de
 * éxito + resoluciones similares (antes, el sidebar realizar-sidebar-rag).
 *   - Escucha `paper-sheet:settled@window` (600 ms sin cambios en la hoja).
 *   - Refresca con debounce: resoluciones (rápido) + probabilidad (1.2 s,
 *     porque usa LLM). Aborta peticiones anteriores en curso.
 *   - Variante reclamación: `analyzeUrl` (POST) en lugar de `probabilityUrl`;
 *     no hay endpoint de resoluciones similares.
 *
 * Targets: similar, probability, similarStatus, probabilityStatus
 * Values:
 *   similarUrl       — endpoint resoluciones-similares.json (solicitud)
 *   probabilityUrl   — endpoint probabilidad.json (solicitud)
 *   analyzeUrl       — endpoint de análisis (reclamación, POST)
 *   multiOrganism    — bool: si true, no calculamos probabilidad
 */
const OUTCOME_LABELS = {
    favorable: 'Estimada',
    partial: 'Estimada parcialmente',
    unfavorable: 'Desestimada',
    inadmissible: 'Inadmitida',
    archivo: 'Archivada',
    desistimiento: 'Desistimiento',
    perdida_objeto: 'Pérdida de objeto',
    acuerdo_mediacion: 'Acuerdo de mediación',
    derivacion: 'Derivada',
};
const OUTCOME_TONE = {
    favorable: 'is-emerald',
    partial: 'is-amber',
    acuerdo_mediacion: 'is-emerald',
    unfavorable: 'is-red',
    inadmissible: 'is-red',
    archivo: 'is-slate',
    desistimiento: 'is-slate',
    perdida_objeto: 'is-slate',
    derivacion: 'is-slate',
};

export default class extends Controller {
    static targets = ['similar', 'probability', 'similarStatus', 'probabilityStatus'];
    static values = {
        similarUrl: { type: String, default: '' },
        probabilityUrl: { type: String, default: '' },
        analyzeUrl: { type: String, default: '' },
        multiOrganism: { type: Boolean, default: false },
        hasDraft: { type: Boolean, default: true },
    };

    connect() {
        this._similarAbort = null;
        this._probabilityAbort = null;
        this._similarTimer = null;
        this._probabilityTimer = null;

        // First paint — load baseline immediately. Without draft content the
        // similar-resolutions match would only key on the organism (noise),
        // so hold an empty state until something is written.
        if (this.similarUrlValue) {
            if (this.hasDraftValue) this._refreshSimilar(0);
            else this._renderSimilarWaiting();
        }
        if (this.multiOrganismValue) this._renderMultiOrganismProbability();
        else this._refreshProbability(0);
    }

    disconnect() {
        if (this._similarAbort) this._similarAbort.abort();
        if (this._probabilityAbort) this._probabilityAbort.abort();
        if (this._similarTimer) clearTimeout(this._similarTimer);
        if (this._probabilityTimer) clearTimeout(this._probabilityTimer);
    }

    /** Bound via data-action="paper-sheet:settled@window->rag-card#onSettled" */
    onSettled(event) {
        this._noteDraftContent(event);
        if (this.similarUrlValue && this.hasDraftValue) this._refreshSimilar(400);
        if (!this.multiOrganismValue) this._refreshProbability(1200);
    }

    /** The chat just applied a generated/rewritten draft — big edit, shorter debounce. */
    onDraftApplied(event) {
        this.hasDraftValue = true;
        if (this.similarUrlValue) this._refreshSimilar(150);
        if (!this.multiOrganismValue) this._refreshProbability(800);
    }

    /** settled events carry the sheet payload; empty payload = still no draft. */
    _noteDraftContent(event) {
        const d = event?.detail;
        if (!d) return;
        const text = [d.title, d.description, d.expone, d.solicita, d.bodyHtml]
            .map((v) => String(v ?? '').trim())
            .join('');
        this.hasDraftValue = text.length > 0;
        if (!this.hasDraftValue && this.similarUrlValue) this._renderSimilarWaiting();
    }

    _renderSimilarWaiting() {
        if (!this.hasSimilarTarget) return;
        this.similarTarget.innerHTML = `
            <div class="rag-empty">
                <i data-lucide="file-edit" class="w-4 h-4"></i>
                <span>Aún no hay borrador. En cuanto escribas algo buscaremos resoluciones parecidas.</span>
            </div>`;
        this._reIcons();
    }

    forceRecalculateProbability(event) {
        event?.preventDefault();
        if (this.multiOrganismValue) return;
        this._refreshProbability(0, true);
    }

    _refreshSimilar(debounceMs) {
        if (this._similarTimer) clearTimeout(this._similarTimer);
        this._similarTimer = setTimeout(() => this._fetchSimilar(), debounceMs);
    }

    _refreshProbability(debounceMs, force = false) {
        if (this._probabilityTimer) clearTimeout(this._probabilityTimer);
        this._probabilityTimer = setTimeout(() => this._fetchProbability(force), debounceMs);
    }

    async _fetchSimilar() {
        if (this._similarAbort) this._similarAbort.abort();
        const ctrl = new AbortController();
        this._similarAbort = ctrl;

        this._setSimilarStatus('Buscando resoluciones similares…');
        try {
            const response = await fetch(this.similarUrlValue, {
                credentials: 'same-origin',
                signal: ctrl.signal,
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const json = await response.json();
            this._renderSimilar(json.resolutions || []);
        } catch (err) {
            if (err.name === 'AbortError') return;
            console.error('similar fetch failed', err);
            this._renderSimilarError();
        } finally {
            this._setSimilarStatus('');
        }
    }

    async _fetchProbability(force) {
        if (this._probabilityAbort) this._probabilityAbort.abort();
        const ctrl = new AbortController();
        this._probabilityAbort = ctrl;

        this._setProbabilityStatus('Calculando…');
        try {
            let json;
            if (this.analyzeUrlValue) {
                // Complaint variant: POST analyze endpoint, {success, analysis}.
                const response = await fetch(this.analyzeUrlValue, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    signal: ctrl.signal,
                });
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                const data = await response.json();
                if (!data.success || !data.analysis) {
                    this._renderEmptyProbability(data.message);
                    return;
                }
                json = data.analysis;
            } else {
                const url = `${this.probabilityUrlValue}${force ? '?force=1' : ''}`;
                const response = await fetch(url, {
                    credentials: 'same-origin',
                    signal: ctrl.signal,
                });
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                json = await response.json();
            }
            if (json.multiOrganism) {
                this._renderMultiOrganismProbability(json.message);
                return;
            }
            if (json.empty) {
                this._renderEmptyProbability(json.message);
                return;
            }
            this._renderProbability(json);
        } catch (err) {
            if (err.name === 'AbortError') return;
            console.error('probability fetch failed', err);
            this._renderProbabilityError();
        } finally {
            this._setProbabilityStatus('');
        }
    }

    _renderSimilar(resolutions) {
        if (!this.hasSimilarTarget) return;
        if (resolutions.length === 0) {
            this.similarTarget.innerHTML = `
                <div class="rag-empty">
                    <i data-lucide="search" class="w-4 h-4"></i>
                    <span>Aún no tenemos resoluciones lo suficientemente parecidas. Sigue escribiendo y volveremos a buscar.</span>
                </div>`;
            this._reIcons();
            return;
        }

        const cards = resolutions.map((r) => {
            const tone = OUTCOME_TONE[r.outcome] || 'is-slate';
            const outcomeLabel = OUTCOME_LABELS[r.outcome] || r.outcome || '—';
            const date = r.date ? this._formatDate(r.date) : '';
            const score = r.score !== null && r.score !== undefined
                ? `<span class="rag-card-score">${this._escape(this._formatScore(r.score))}</span>`
                : '';
            const body = r.publicBody ? this._escape(r.publicBody) : '—';
            const summary = r.summary ? this._escape(r.summary) : '';
            return `
                <article class="rag-card">
                    <header class="rag-card-head">
                        <span class="rag-status ${tone}">${this._escape(outcomeLabel)}</span>
                        ${score}
                    </header>
                    <h4 class="rag-card-ref">${this._escape(r.reference || '')}</h4>
                    <p class="rag-card-body">${body}</p>
                    ${summary ? `<p class="rag-card-summary">${summary}</p>` : ''}
                    ${date ? `<footer class="rag-card-foot">${this._escape(date)}</footer>` : ''}
                </article>`;
        }).join('');

        this.similarTarget.innerHTML = cards;
    }

    _renderSimilarError() {
        if (!this.hasSimilarTarget) return;
        this.similarTarget.innerHTML = `
            <div class="rag-empty rag-error">
                <i data-lucide="alert-triangle" class="w-4 h-4"></i>
                <span>No hemos podido recuperar resoluciones similares. Lo reintentaremos en cuanto cambies el borrador.</span>
            </div>`;
        this._reIcons();
    }

    _renderProbability({ percentage, summary, strengths, weaknesses }) {
        if (!this.hasProbabilityTarget) return;
        const pct = Math.max(0, Math.min(100, parseInt(percentage, 10) || 50));
        const zone = this._zoneFor(pct);

        this.probabilityTarget.innerHTML = `
            <div class="verdict">
                <span class="verdict-num">${pct}</span>
                <span class="verdict-frac">/100</span>
            </div>
            <div class="verdict-label">
                Probabilidad de éxito
                <span class="verdict-zone ${zone.cssClass}">${this._escape(zone.label)}</span>
            </div>
            <div class="gauge">
                <div class="gauge-track"></div>
                <div class="gauge-ticks">
                    <span></span><span></span><span></span><span></span><span></span>
                </div>
                <div class="gauge-marker" style="left:${pct}%"></div>
            </div>
            <div class="gauge-scale">
                <span>0</span><span>25</span><span>50</span><span>75</span><span>100</span>
            </div>
            ${summary ? `<div class="brief-section"><p class="brief-summary brief-html">${this._sanitize(summary)}</p></div>` : ''}
            ${strengths ? `<div class="brief-section">
                <h3 class="brief-section-title is-pos">
                    <span class="mark"><i data-lucide="check" class="w-2.5 h-2.5"></i></span>
                    A favor
                </h3>
                <div class="brief-html">${this._sanitize(strengths)}</div>
            </div>` : ''}
            ${weaknesses ? `<div class="brief-section">
                <h3 class="brief-section-title is-neg">
                    <span class="mark"><i data-lucide="alert-triangle" class="w-2.5 h-2.5"></i></span>
                    En contra
                </h3>
                <div class="brief-html">${this._sanitize(weaknesses)}</div>
            </div>` : ''}
            <button type="button"
                    class="ghost-link mt-4"
                    data-action="rag-card#forceRecalculateProbability">
                <i data-lucide="refresh-cw" class="w-3 h-3"></i> Recalcular informe
            </button>`;
        this._reIcons();
    }

    _renderEmptyProbability(message) {
        if (!this.hasProbabilityTarget) return;
        this.probabilityTarget.innerHTML = `
            <div class="brief-html" style="display:flex;gap:.5rem;align-items:flex-start;">
                <i data-lucide="file-edit" class="w-4 h-4" style="color:#94a3b8;flex-shrink:0;margin-top:.15rem;"></i>
                <span>${this._escape(message || 'Empieza a escribir el borrador y calcularemos la probabilidad.')}</span>
            </div>`;
        this._reIcons();
    }

    _renderMultiOrganismProbability(message) {
        if (!this.hasProbabilityTarget) return;
        this.probabilityTarget.innerHTML = `
            <div class="verdict-multi">
                <i data-lucide="layers" class="w-4 h-4"></i>
                <span>${this._escape(message || 'La probabilidad se calcula sólo cuando el destinatario es uno.')}</span>
            </div>`;
        this._reIcons();
    }

    _renderProbabilityError() {
        if (!this.hasProbabilityTarget) return;
        this.probabilityTarget.innerHTML = `
            <div class="brief-html rag-error" style="display:flex;gap:.5rem;align-items:flex-start;">
                <i data-lucide="alert-triangle" class="w-4 h-4"></i>
                <span>No hemos podido calcular la probabilidad ahora. Inténtalo de nuevo.</span>
            </div>`;
        this._reIcons();
    }

    _zoneFor(pct) {
        if (pct >= 80) return { cssClass: 'zone-high', label: 'Alta' };
        if (pct >= 60) return { cssClass: 'zone-mid-high', label: 'Media-alta' };
        if (pct >= 40) return { cssClass: 'zone-mid', label: 'Media' };
        if (pct >= 20) return { cssClass: 'zone-mid-low', label: 'Media-baja' };
        return { cssClass: 'zone-low', label: 'Baja' };
    }

    _setSimilarStatus(text) {
        if (this.hasSimilarStatusTarget) this.similarStatusTarget.textContent = text;
    }
    _setProbabilityStatus(text) {
        if (this.hasProbabilityStatusTarget) this.probabilityStatusTarget.textContent = text;
    }

    _formatDate(iso) {
        try {
            const d = new Date(iso);
            if (isNaN(d.getTime())) return iso;
            const months = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
            return `${d.getDate()} ${months[d.getMonth()]} ${d.getFullYear()}`;
        } catch {
            return iso;
        }
    }

    _formatScore(score) {
        const n = parseFloat(score);
        if (isNaN(n)) return '';
        return `match ${(n * 100).toFixed(0)}%`;
    }

    _sanitize(html) {
        return String(html ?? '').replace(/<(?!\/?(b|i|br|ul|li)\b)[^>]+>/gi, '');
    }

    _escape(s) {
        return String(s ?? '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
        }[c]));
    }

    _reIcons() {
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    }
}
