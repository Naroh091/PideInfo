import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';

/**
 * Picker para "Realizar solicitud":
 *   - tom-select remoto contra /solicitudes/nueva/realizar/organismos.json
 *   - badge "Portal Transparencia" o "REG" en cada opción
 *   - tarjeta de previsualización a la derecha (single) o chips (multi)
 *   - para organismos REG (requiresRegDestination=true), un segundo selector de
 *     Unidad por organismo dentro de la previsualización
 *   - "Continuar" hace POST a /iniciar con `targets:[{publicBodyId, regDestinationId?}]`
 *
 * Targets / values declared as before; new value:
 *   unitsUrlTemplate → ruta de unidades, con {id} para el publicBody
 */
export default class extends Controller {
    static targets = ['select', 'preview', 'continueButton'];
    static values = {
        loadUrl: String,
        initiateUrl: String,
        unitsUrlTemplate: String,
        csrfToken: String,
    };

    connect() {
        this.bodiesById = new Map();
        this.selectedUnitsByBody = new Map();
        this.unitTomSelects = new Map();

        const escape = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
        }[c]));

        const labelOf = (data) => {
            const meta = this.bodiesById.get(data.id) || {};
            return data.name || meta.name || data.text || '';
        };

        const channelBadge = (channel, channelLabel) => {
            if (!channelLabel) return '';
            const cls = channel === 'submit_request_transparencia'
                ? 'channel-badge channel-badge-portal channel-badge-sm'
                : 'channel-badge channel-badge-reg channel-badge-sm';
            return `<span class="${cls}">${escape(channelLabel)}</span>`;
        };

        const renderOption = (data, esc) => {
            const meta = this.bodiesById.get(data.id) || {};
            const channel = meta.channel || data.channel || '';
            const channelLabel = meta.channelLabel || data.channelLabel || '';
            const law = (meta.applicableLaw || data.applicableLaw)?.shortCode || '';
            const deadline = (meta.applicableLaw || data.applicableLaw)?.deadlineLabel || '';
            // Show CCAA only for sub-state bodies — for state-level organisms
            // it's redundant noise.
            const level = meta.level || data.level || '';
            const ccaa = (meta.autonomousCommunity || data.autonomousCommunity || '');
            const territory = (level === 'autonomous' || level === 'local') && ccaa ? ccaa : '';
            const metaParts = [territory, deadline, law].filter(Boolean).map(escape);
            const lawLine = metaParts.length > 0
                ? `<span class="picker-option-meta">${metaParts.join(' · ')}</span>`
                : '';
            return `<div class="picker-option">
                <div class="picker-option-row">
                    <span class="picker-option-name">${escape(labelOf(data))}</span>
                    ${channelBadge(channel, channelLabel)}
                </div>
                ${lawLine}
            </div>`;
        };

        const renderItem = (data, esc) => {
            const meta = this.bodiesById.get(data.id) || {};
            const channel = meta.channel || data.channel || '';
            const channelLabel = meta.channelLabel || data.channelLabel || '';
            return `<div class="picker-chip"><span>${escape(labelOf(data))}</span>${channelBadge(channel, channelLabel)}</div>`;
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
            onItemAdd: () => this._refreshPreview(),
            onItemRemove: (value) => {
                this.selectedUnitsByBody.delete(value);
                const ts = this.unitTomSelects.get(value);
                if (ts) { ts.destroy(); this.unitTomSelects.delete(value); }
                this._refreshPreview();
            },
        });

        this._refreshPreview();
    }

    disconnect() {
        if (this.tomSelect) this.tomSelect.destroy();
        this.unitTomSelects.forEach((ts) => ts.destroy());
        this.unitTomSelects.clear();
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

        this._updateContinueButton(bodies);

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
        } else {
            const chips = bodies.map((b) => `<li class="preview-chip" data-body-id="${this._escape(b.id)}">
                <div class="preview-chip-head">
                    <span class="preview-chip-name">${this._escape(b.name)}</span>
                    <span class="channel-badge ${b.channel === 'submit_request_transparencia' ? 'channel-badge-portal' : 'channel-badge-reg'} channel-badge-sm">${this._escape(b.channelLabel)}</span>
                </div>
                ${b.requiresRegDestination ? `<div class="preview-chip-unit"><label class="picker-card-label">Unidad de destino</label><select data-unit-select-for="${this._escape(b.id)}"></select></div>` : ''}
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
        }

        bodies.filter((b) => b.requiresRegDestination).forEach((b) => this._mountUnitSelector(b));

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

        const badgeClass = body.channel === 'submit_request_transparencia'
            ? 'channel-badge channel-badge-portal'
            : 'channel-badge channel-badge-reg';

        const unitBlock = body.requiresRegDestination
            ? `<div class="preview-card-unit">
                  <p class="picker-card-label">Unidad de destino del REG</p>
                  <select data-unit-select-for="${this._escape(body.id)}"></select>
                  <p class="picker-help" style="margin-top:.5rem;">Selecciona la unidad concreta a la que va dirigida tu solicitud. Si tu organismo tiene oficinas territoriales, podrás filtrar por provincia.</p>
               </div>`
            : '';

        return `
            <article class="preview-card">
                <header class="preview-card-head">
                    <span class="${badgeClass}">${this._escape(body.channelLabel)}</span>
                    <span class="preview-meta-pill">${this._escape(body.levelLabel)}</span>
                    ${ccaa}
                </header>
                <h2 class="preview-card-title">${this._escape(body.name)}</h2>
                ${lawLine}
                ${unitBlock}
            </article>`;
    }

    _mountUnitSelector(body) {
        const select = this.previewTarget.querySelector(`select[data-unit-select-for="${CSS.escape(body.id)}"]`);
        if (!select) return;

        const url = (this.unitsUrlTemplateValue || '').replace('__body__', body.id);
        const ts = new TomSelect(select, {
            valueField: 'id',
            labelField: 'displayLabel',
            // Search across the whole hierarchy so the user can find a unit
            // by Oficina or Raíz name/code, not just the Unidad label.
            searchField: ['displayLabel', 'name', 'dir3', 'oficinaName', 'oficinaDir3', 'raizName', 'raizDir3', 'provincia'],
            preload: 'focus',
            maxOptions: 100,
            plugins: { dropdown_input: {} },
            load: (q, cb) => {
                fetch(`${url}?q=${encodeURIComponent(q)}&limit=100`, { credentials: 'same-origin' })
                    .then((r) => r.json())
                    .then((json) => cb(json.units || []))
                    .catch(() => cb());
            },
            render: {
                option: (data, esc) => {
                    // Three lines: Unidad (bold) / Oficina / Raíz. Each line
                    // shows "[DIR3] Nombre"; missing pieces render as "—" so
                    // the structure stays readable even on legacy rows that
                    // haven't been re-imported with Oficina yet.
                    const line = (code, name) => `
                        <div class="picker-option-row">
                            <span class="picker-option-dir3">${esc(code || '—')}</span>
                            <span class="picker-option-name">${esc(name || '—')}</span>
                        </div>`;
                    return `<div class="picker-option picker-option-tree">
                        <div class="picker-option-line picker-option-line-unit">${line(data.dir3, data.name)}</div>
                        <div class="picker-option-line picker-option-line-oficina">${line(data.oficinaDir3, data.oficinaName)}</div>
                        <div class="picker-option-line picker-option-line-raiz">
                            ${line(data.raizDir3, data.raizName)}
                            ${data.provincia ? `<span class="picker-option-meta">${esc(data.provincia)}</span>` : ''}
                        </div>
                    </div>`;
                },
            },
            onChange: (value) => {
                if (value) {
                    this.selectedUnitsByBody.set(body.id, value);
                } else {
                    this.selectedUnitsByBody.delete(body.id);
                }
                this._updateContinueButton();
            },
        });

        const previous = this.selectedUnitsByBody.get(body.id);
        if (previous) ts.setValue(previous, true);

        this.unitTomSelects.set(body.id, ts);
    }

    _updateContinueButton(bodies = null) {
        if (!this.hasContinueButtonTarget) return;
        if (bodies === null) {
            const ids = this.tomSelect.getValue();
            const list = Array.isArray(ids) ? ids : (ids ? [ids] : []);
            bodies = list.map((id) => this.bodiesById.get(id)).filter(Boolean);
        }
        const missingUnit = bodies.some((b) => b.requiresRegDestination && !this.selectedUnitsByBody.get(b.id));
        this.continueButtonTarget.disabled = bodies.length === 0 || missingUnit;
    }

    async submit(event) {
        event.preventDefault();
        const ids = this.tomSelect.getValue();
        const list = Array.isArray(ids) ? ids : (ids ? [ids] : []);
        if (list.length === 0) return;

        const targets = list.map((id) => {
            const body = this.bodiesById.get(id) || {};
            const target = { publicBodyId: id };
            if (body.requiresRegDestination) {
                target.regDestinationId = this.selectedUnitsByBody.get(id);
            }
            return target;
        });

        this.continueButtonTarget.disabled = true;
        this.continueButtonTarget.dataset.previousLabel = this.continueButtonTarget.textContent;
        this.continueButtonTarget.innerHTML = 'Preparando borradores…';

        try {
            const response = await fetch(this.initiateUrlValue, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ targets }),
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
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
