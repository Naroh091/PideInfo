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
            const json = await this._postInitiate(targets);
            window.location.href = json.firstDraftUrl;
        } catch (err) {
            if (err && err.code === 'needs_profile') {
                // Pause the flow, collect the missing REG personal data via a
                // modal, then retry the initiate POST without making the user
                // re-select the organisms.
                await this._handleNeedsProfile(err.profileFormUrl, targets);
                return;
            }
            console.error(err);
            this.continueButtonTarget.disabled = false;
            this.continueButtonTarget.textContent = this.continueButtonTarget.dataset.previousLabel || 'Continuar';
            alert('No se ha podido iniciar el borrador. Inténtalo de nuevo.');
        }
    }

    async _postInitiate(targets) {
        const response = await fetch(this.initiateUrlValue, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ targets }),
        });
        if (response.status === 422) {
            const data = await response.json().catch(() => ({}));
            if (data && data.error === 'needs_profile') {
                const e = new Error('needs_profile');
                e.code = 'needs_profile';
                e.profileFormUrl = data.profileFormUrl;
                throw e;
            }
        }
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        return response.json();
    }

    async _handleNeedsProfile(profileFormUrl, targets) {
        try {
            const html = await fetch(profileFormUrl, {
                credentials: 'same-origin',
                headers: { 'Accept': 'text/html' },
            }).then((r) => {
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                return r.text();
            });

            const modal = this._openProfileModal(html);
            const submitted = await modal.waitForSubmit();
            modal.close();

            if (!submitted) {
                // User dismissed the modal — re-enable the continue button.
                this.continueButtonTarget.disabled = false;
                this.continueButtonTarget.textContent = this.continueButtonTarget.dataset.previousLabel || 'Continuar';
                return;
            }

            this.continueButtonTarget.innerHTML = 'Preparando borradores…';
            const json = await this._postInitiate(targets);
            window.location.href = json.firstDraftUrl;
        } catch (err) {
            console.error(err);
            this.continueButtonTarget.disabled = false;
            this.continueButtonTarget.textContent = this.continueButtonTarget.dataset.previousLabel || 'Continuar';
            alert('No se ha podido guardar tus datos personales. Inténtalo de nuevo.');
        }
    }

    _openProfileModal(formHtml) {
        const overlay = document.createElement('div');
        overlay.className = 'profile-modal-overlay';
        overlay.innerHTML = `
            <div class="profile-modal" role="dialog" aria-modal="true" aria-labelledby="profile-modal-title">
                <header class="profile-modal-head">
                    <div>
                        <p class="profile-modal-eyebrow">Antes de continuar</p>
                        <h2 class="profile-modal-title" id="profile-modal-title">Necesitamos tus datos para el REG</h2>
                        <p class="profile-modal-sub">El Registro Electrónico Común exige estos datos para identificarte como solicitante. Se guardan en tu perfil y no tendrás que volver a introducirlos.</p>
                    </div>
                    <button type="button" class="profile-modal-close" aria-label="Cerrar">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </header>
                <div class="profile-modal-body"></div>
                <div class="profile-modal-error" role="alert" hidden></div>
            </div>`;

        overlay.querySelector('.profile-modal-body').innerHTML = formHtml;
        document.body.appendChild(overlay);
        document.body.style.overflow = 'hidden';

        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }

        // Re-trigger Stimulus on the injected form so the reg-address-form
        // controller mounts its Tom-Select pickers. The Stimulus application
        // observes the DOM, but injected nodes need a tick to be picked up;
        // we just rely on the MutationObserver here.

        const form = overlay.querySelector('form');
        const errorBox = overlay.querySelector('.profile-modal-error');
        const closeBtn = overlay.querySelector('.profile-modal-close');

        let resolveSubmit;
        const waitForSubmit = new Promise((resolve) => { resolveSubmit = resolve; });

        const close = () => {
            document.body.style.overflow = '';
            overlay.remove();
        };

        closeBtn.addEventListener('click', () => resolveSubmit(false));
        overlay.addEventListener('click', (ev) => {
            if (ev.target === overlay) resolveSubmit(false);
        });

        form.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            errorBox.hidden = true;
            errorBox.textContent = '';

            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;

            try {
                const action = form.getAttribute('action') || window.location.pathname;
                const formData = new FormData(form);
                const response = await fetch(action, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData,
                });

                if (response.ok) {
                    resolveSubmit(true);
                    return;
                }

                const data = await response.json().catch(() => ({}));
                const messages = [];
                if (data && data.errors) {
                    Object.values(data.errors).forEach((arr) => arr.forEach((m) => messages.push(m)));
                }
                errorBox.textContent = messages.length > 0
                    ? messages.join(' · ')
                    : 'No se han podido guardar los datos. Revisa los campos.';
                errorBox.hidden = false;
            } catch (err) {
                console.error(err);
                errorBox.textContent = 'Error de red al guardar los datos. Inténtalo de nuevo.';
                errorBox.hidden = false;
            } finally {
                if (submitBtn) submitBtn.disabled = false;
            }
        });

        return { waitForSubmit: () => waitForSubmit, close };
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
