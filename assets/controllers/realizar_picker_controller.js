import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';

/**
 * Picker para "Realizar solicitud":
 *   - tom-select remoto contra /solicitudes/nueva/realizar/organismos.json
 *   - badge "Portal Transparencia" en cada opción
 *   - tarjeta de previsualización a la derecha que muestra ley aplicable y plazos
 *     (single) o aviso multi-organismo + chips (multi)
 *   - botón "Continuar a la redacción" hace POST a /iniciar y redirige al primer borrador
 *
 * Targets:
 *   select         → el <select multiple>
 *   preview        → contenedor del card de previsualización
 *   continueButton → CTA submit
 *
 * Values:
 *   loadUrl     → endpoint JSON de organismos
 *   initiateUrl → endpoint POST que crea los borradores
 *   csrfToken   → token CSRF para el POST (extraído de meta o input hidden)
 */
export default class extends Controller {
    static targets = ['select', 'preview', 'continueButton'];
    static values = {
        loadUrl: String,
        initiateUrl: String,
        csrfToken: String,
    };

    connect() {
        this.bodiesById = new Map();

        const escape = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
        }[c]));

        // Tom-Select with labelField:'name' exposes the body name on data.name
        // (data.text is empty). We fall back to bodiesById too in case the option
        // was added through preload/restore where some fields may be missing.
        const labelOf = (data) => {
            const meta = this.bodiesById.get(data.id) || {};
            return data.name || meta.name || data.text || '';
        };

        const renderOption = (data, esc) => {
            const meta = this.bodiesById.get(data.id) || {};
            const channel = meta.channel || data.channel || '';
            const channelLabel = meta.channelLabel || data.channelLabel || '';
            const law = (meta.applicableLaw || data.applicableLaw)?.shortCode || '';
            const deadline = (meta.applicableLaw || data.applicableLaw)?.deadlineLabel || '';
            const lawLine = (law || deadline)
                ? `<span class="picker-option-meta">${escape(deadline)}${deadline && law ? ' · ' : ''}${escape(law)}</span>`
                : '';
            const badgeClass = channel === 'submit_request_transparencia'
                ? 'channel-badge channel-badge-portal channel-badge-sm'
                : 'channel-badge channel-badge-reg channel-badge-sm';
            const badge = channelLabel
                ? `<span class="${badgeClass}">${escape(channelLabel)}</span>`
                : '';
            return `<div class="picker-option">
                <div class="picker-option-row">
                    <span class="picker-option-name">${escape(labelOf(data))}</span>
                    ${badge}
                </div>
                ${lawLine}
            </div>`;
        };

        const renderItem = (data, esc) => {
            const meta = this.bodiesById.get(data.id) || {};
            const channel = meta.channel || data.channel || '';
            const channelLabel = meta.channelLabel || data.channelLabel || '';
            const badgeClass = channel === 'submit_request_transparencia'
                ? 'channel-badge channel-badge-portal channel-badge-sm'
                : 'channel-badge channel-badge-reg channel-badge-sm';
            const badge = channelLabel
                ? `<span class="${badgeClass}">${escape(channelLabel)}</span>`
                : '';
            return `<div class="picker-chip"><span>${escape(labelOf(data))}</span>${badge}</div>`;
        };

        this.tomSelect = new TomSelect(this.selectTarget, {
            valueField: 'id',
            labelField: 'name',
            searchField: ['name'],
            preload: 'focus',
            plugins: { dropdown_input: {}, remove_button: {} },
            load: (query, callback) => this._loadOptions(query, callback),
            render: { option: renderOption, item: renderItem },
            onChange: () => this._refreshPreview(),
            onItemAdd: (value) => this._refreshPreview(),
            onItemRemove: () => this._refreshPreview(),
        });

        this._refreshPreview();
    }

    disconnect() {
        if (this.tomSelect) this.tomSelect.destroy();
    }

    _loadOptions(query, callback) {
        const url = `${this.loadUrlValue}?q=${encodeURIComponent(query)}&limit=25`;
        fetch(url, { credentials: 'same-origin' })
            .then((r) => r.json())
            .then((json) => {
                const bodies = (json && json.bodies) || [];
                bodies.forEach((b) => this.bodiesById.set(b.id, b));
                callback(bodies);
            })
            .catch(() => callback());
    }

    _refreshPreview() {
        const ids = this.tomSelect.getValue();
        const list = Array.isArray(ids) ? ids : (ids ? [ids] : []);
        const bodies = list.map((id) => this.bodiesById.get(id)).filter(Boolean);

        if (this.hasContinueButtonTarget) {
            this.continueButtonTarget.disabled = bodies.length === 0;
        }

        if (!this.hasPreviewTarget) return;

        if (bodies.length === 0) {
            this.previewTarget.innerHTML = `
                <div class="preview-empty">
                    <div class="preview-empty-icon"><i data-lucide="search" class="w-5 h-5"></i></div>
                    <div>
                        <p class="preview-empty-title">Empieza eligiendo el organismo</p>
                        <p class="preview-empty-body">Cuando lo selecciones aparecerá aquí su ley aplicable y los plazos.</p>
                    </div>
                </div>`;
            this._reIcons();
            return;
        }

        if (bodies.length === 1) {
            this.previewTarget.innerHTML = this._singleOrganismCard(bodies[0]);
            this._reIcons();
            return;
        }

        const chips = bodies.map((b) => `<li class="preview-chip">
            <span class="preview-chip-name">${this._escape(b.name)}</span>
            <span class="channel-badge channel-badge-portal channel-badge-sm">${this._escape(b.channelLabel)}</span>
        </li>`).join('');

        this.previewTarget.innerHTML = `
            <div class="preview-multi">
                <div class="preview-multi-head">
                    <i data-lucide="layers" class="w-5 h-5"></i>
                    <h3>Vas a enviar a ${bodies.length} organismos</h3>
                </div>
                <p class="preview-multi-note">PideInfo creará una solicitud independiente para cada uno. La probabilidad de éxito solo se calcula cuando el destinatario es uno.</p>
                <ul class="preview-multi-list">${chips}</ul>
            </div>`;
        this._reIcons();
    }

    _singleOrganismCard(body) {
        const law = body.applicableLaw || {};
        const lawLine = law.name
            ? `<dl class="preview-law">
                <dt>Ley aplicable</dt>
                <dd>${this._escape(law.name)} <span class="preview-law-code">${this._escape(law.shortCode || '')}</span></dd>
                <dt>Plazo de respuesta</dt>
                <dd>${this._escape(law.deadlineLabel || '—')}</dd>
              </dl>`
            : '<p class="preview-law-empty">Sin ley aplicable resoluta automáticamente. Podrás elegirla en el siguiente paso.</p>';

        const ccaa = body.autonomousCommunity
            ? `<span class="preview-meta-pill">${this._escape(body.autonomousCommunity)}</span>`
            : '';

        return `
            <article class="preview-card">
                <header class="preview-card-head">
                    <span class="channel-badge channel-badge-portal">${this._escape(body.channelLabel)}</span>
                    <span class="preview-meta-pill">${this._escape(body.levelLabel)}</span>
                    ${ccaa}
                </header>
                <h2 class="preview-card-title">${this._escape(body.name)}</h2>
                ${lawLine}
            </article>`;
    }

    async submit(event) {
        event.preventDefault();
        const ids = this.tomSelect.getValue();
        const list = Array.isArray(ids) ? ids : (ids ? [ids] : []);
        if (list.length === 0) return;

        this.continueButtonTarget.disabled = true;
        this.continueButtonTarget.dataset.previousLabel = this.continueButtonTarget.textContent;
        this.continueButtonTarget.innerHTML = 'Preparando borradores…';

        try {
            const response = await fetch(this.initiateUrlValue, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ publicBodyIds: list }),
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            const json = await response.json();
            window.location.href = json.firstDraftUrl;
        } catch (err) {
            console.error(err);
            this.continueButtonTarget.disabled = false;
            this.continueButtonTarget.textContent = this.continueButtonTarget.dataset.previousLabel || 'Continuar';
            alert('No se ha podido iniciar el borrador. Inténtalo de nuevo.');
        }
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
