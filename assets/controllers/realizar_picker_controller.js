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
    static targets = [
        'nivel', 'facet', 'facetStep', 'facetLabel',
        'select', 'unit', 'unitStep',
        'addButton', 'continueButton', 'preview',
    ];
    static values = {
        loadUrl: String,
        facetsUrl: String,
        initiateUrl: String,
        unitsUrlTemplate: String,
        csrfToken: String,
        // When true the picker creates draft-only requests (not queued for
        // sending): the initiate POST carries `draftOnly: true` and the copy
        // reflects "guardar" rather than "enviar".
        draftOnly: Boolean,
    };

    connect() {
        // Destinatarios acumulados: [{publicBodyId, regDestinationId?, name, channel, channelLabel, levelLabel, unitLabel?, law?}]
        this.selectedTargets = [];
        this.currentBody = null;     // organismo elegido en paso 3 (objeto JSON del endpoint)
        this.currentUnit = null;     // {id, displayLabel} elegido en paso 4

        this._mountOrganismSelect();
        this._renderTargets();
        this._updateAddButton();
        this._updateContinueButton();
    }

    disconnect() {
        if (this.organismTs) this.organismTs.destroy();
        if (this.unitTs) this.unitTs.destroy();
    }

    // ── Paso 1: nivel ──
    nivelChanged() {
        const nivel = this.nivelTarget.value;
        this.currentBody = null;
        this.currentUnit = null;
        this._clearOrganism();
        this._clearUnit();

        if (!nivel) {
            this.facetStepTarget.hidden = true;
            this._updateAddButton();
            return;
        }

        fetch(`${this.facetsUrlValue}?nivel=${encodeURIComponent(nivel)}`, { credentials: 'same-origin' })
            .then((r) => r.json())
            .then((json) => {
                const options = json.options || [];
                if ((json.facetType || 'none') === 'none' || options.length === 0) {
                    this.facetStepTarget.hidden = true;
                    return;
                }
                // El número del paso lo pone el contador CSS; aquí solo el texto.
                this.facetLabelTarget.textContent = json.facetType === 'ministerio'
                    ? 'Ministerio' : 'Comunidad autónoma';
                this.facetTarget.innerHTML = '<option value="">Todos</option>'
                    + options.map((o) => `<option value="${this._escape(o)}">${this._escape(o)}</option>`).join('');
                this.facetStepTarget.hidden = false;
            })
            .catch(() => { this.facetStepTarget.hidden = true; });

        this._updateAddButton();
    }

    // ── Paso 2: ámbito ──
    facetChanged() {
        this.currentBody = null;
        this.currentUnit = null;
        this._clearOrganism();
        this._clearUnit();
        this._updateAddButton();
    }

    // ── Paso 3: organismo ──
    organismChanged() {
        const id = this.organismTs ? this.organismTs.getValue() : '';
        this.currentUnit = null;
        this._clearUnit();
        this.currentBody = id ? (this.bodiesById.get(id) || null) : null;

        if (this.currentBody && this.currentBody.requiresRegDestination) {
            this._mountUnitSelect(this.currentBody);
            this.unitStepTarget.hidden = false;
        } else {
            this.unitStepTarget.hidden = true;
        }
        this._updateAddButton();
    }

    // ── Añadir destinatario al panel ──
    addTarget() {
        if (!this._canAdd()) return;
        const b = this.currentBody;
        const entry = {
            publicBodyId: b.id,
            name: b.name,
            channel: b.channel,
            channelLabel: b.channelLabel,
            levelLabel: b.levelLabel,
            law: b.applicableLaw || null,
        };
        if (b.requiresRegDestination) {
            entry.regDestinationId = this.currentUnit.id;
            entry.unitLabel = this.currentUnit.displayLabel;
        }
        // Evitar duplicado exacto (mismo organismo + misma unidad).
        const dup = this.selectedTargets.some((t) => t.publicBodyId === entry.publicBodyId
            && (t.regDestinationId || null) === (entry.regDestinationId || null));
        if (!dup) this.selectedTargets.push(entry);

        // Reset de la cascada para añadir otro.
        this.nivelTarget.value = '';
        this.facetStepTarget.hidden = true;
        this.unitStepTarget.hidden = true;
        this._clearOrganism();
        this._clearUnit();
        this.currentBody = null;
        this.currentUnit = null;

        this._renderTargets();
        this._updateAddButton();
        this._updateContinueButton();
    }

    removeTarget(event) {
        const idx = Number(event.currentTarget.dataset.index);
        if (Number.isInteger(idx)) {
            this.selectedTargets.splice(idx, 1);
            this._renderTargets();
            this._updateContinueButton();
        }
    }

    // ── Helpers de montaje ──
    _mountOrganismSelect() {
        this.bodiesById = new Map();
        const escape = (s) => this._escape(s);

        this.organismTs = new TomSelect(this.selectTarget, {
            valueField: 'id',
            labelField: 'name',
            searchField: ['name'],
            maxItems: 1,
            preload: 'focus',
            plugins: { dropdown_input: {} },
            load: (query, callback) => {
                const params = new URLSearchParams({ q: query, limit: '25' });
                const nivel = this.nivelTarget.value;
                if (nivel) params.set('nivel', nivel);
                const facet = this.hasFacetTarget && !this.facetStepTarget.hidden ? this.facetTarget.value : '';
                if (facet) {
                    params.set(nivel === 'estado' ? 'ministerio' : 'comunidad', facet);
                }
                fetch(`${this.loadUrlValue}?${params.toString()}`, { credentials: 'same-origin' })
                    .then((r) => r.json())
                    .then((json) => {
                        const bodies = (json && json.bodies) || [];
                        bodies.forEach((b) => this.bodiesById.set(b.id, b));
                        callback(bodies);
                    })
                    .catch(() => callback());
            },
            render: {
                option: (data, esc) => {
                    const law = data.applicableLaw?.shortCode || '';
                    const deadline = data.applicableLaw?.deadlineLabel || '';
                    const territory = (data.level === 'autonomous' || data.level === 'local') && data.autonomousCommunity
                        ? data.autonomousCommunity : '';
                    const metaParts = [territory, deadline, law].filter(Boolean).map(escape);
                    const meta = metaParts.length ? `<span class="picker-option-meta">${metaParts.join(' · ')}</span>` : '';
                    const badge = data.channelLabel
                        ? `<span class="channel-badge ${data.channel === 'submit_request_transparencia' ? 'channel-badge-portal' : 'channel-badge-reg'} channel-badge-sm">${escape(data.channelLabel)}</span>`
                        : '';
                    return `<div class="picker-option"><div class="picker-option-row"><span class="picker-option-name">${escape(data.name)}</span>${badge}</div>${meta}</div>`;
                },
            },
            onChange: () => this.organismChanged(),
        });
    }

    _mountUnitSelect(body) {
        if (this.unitTs) { this.unitTs.destroy(); this.unitTs = null; }
        const url = (this.unitsUrlTemplateValue || '').replace('__body__', body.id);
        this.unitTs = new TomSelect(this.unitTarget, {
            valueField: 'id',
            labelField: 'displayLabel',
            searchField: ['displayLabel', 'name', 'dir3', 'oficinaName', 'oficinaDir3', 'raizName', 'raizDir3', 'provincia'],
            maxItems: 1,
            preload: 'focus',
            maxOptions: 100,
            plugins: { dropdown_input: {} },
            load: (q, cb) => {
                fetch(`${url}?q=${encodeURIComponent(q)}&limit=100`, { credentials: 'same-origin' })
                    .then((r) => r.json())
                    .then((json) => {
                        this._unitsById = this._unitsById || new Map();
                        (json.units || []).forEach((u) => this._unitsById.set(u.id, u));
                        cb(json.units || []);
                    })
                    .catch(() => cb());
            },
            render: {
                option: (data, esc) => {
                    const line = (code, name) => `<div class="picker-option-row"><span class="picker-option-dir3">${esc(code || '—')}</span><span class="picker-option-name">${esc(name || '—')}</span></div>`;
                    return `<div class="picker-option picker-option-tree">
                        <div class="picker-option-line picker-option-line-unit">${line(data.dir3, data.name)}</div>
                        <div class="picker-option-line picker-option-line-oficina">${line(data.oficinaDir3, data.oficinaName)}</div>
                        <div class="picker-option-line picker-option-line-raiz">${line(data.raizDir3, data.raizName)}${data.provincia ? `<span class="picker-option-meta">${esc(data.provincia)}</span>` : ''}</div>
                    </div>`;
                },
            },
            onChange: (value) => {
                const u = value && this._unitsById ? this._unitsById.get(value) : null;
                this.currentUnit = u ? { id: u.id, displayLabel: u.displayLabel } : null;
                this._updateAddButton();
            },
        });
    }

    _clearOrganism() {
        if (this.organismTs) { this.organismTs.clear(true); this.organismTs.clearOptions(); }
        this.unitStepTarget.hidden = true;
    }

    _clearUnit() {
        if (this.unitTs) { this.unitTs.destroy(); this.unitTs = null; }
        this.currentUnit = null;
    }

    // ── Estado de botones ──
    _canAdd() {
        if (!this.currentBody) return false;
        if (this.currentBody.requiresRegDestination && !this.currentUnit) return false;
        return true;
    }

    _updateAddButton() {
        if (this.hasAddButtonTarget) this.addButtonTarget.disabled = !this._canAdd();
    }

    _updateContinueButton() {
        if (this.hasContinueButtonTarget) this.continueButtonTarget.disabled = this.selectedTargets.length === 0;
    }

    // ── Panel derecho ──
    _renderTargets() {
        if (!this.hasPreviewTarget) return;
        const emptyBody = this.draftOnlyValue
            ? 'Completa el formulario y pulsa "Añadir destinatario". Aquí verás la lista de organismos para los que redactaremos tu solicitud.'
            : 'Completa el formulario y pulsa "Añadir destinatario". Aquí verás la lista de organismos a los que enviaremos tu solicitud.';
        if (this.selectedTargets.length === 0) {
            this.previewTarget.innerHTML = `
                <div class="preview-empty">
                    <div class="preview-empty-icon"><i data-lucide="users" class="w-5 h-5"></i></div>
                    <div>
                        <p class="preview-empty-title">Aún no has añadido destinatarios</p>
                        <p class="preview-empty-body">${emptyBody}</p>
                    </div>
                </div>`;
            this._reIcons();
            return;
        }

        const cards = this.selectedTargets.map((t, i) => {
            const badgeClass = t.channel === 'submit_request_transparencia' ? 'channel-badge-portal' : 'channel-badge-reg';
            const lawLine = t.law && t.law.name
                ? `<div class="target-card-unit">${this._escape(t.law.shortCode || t.law.name)} · ${this._escape(t.law.deadlineLabel || '—')}</div>` : '';
            const unitLine = t.unitLabel ? `<div class="target-card-unit">└ ${this._escape(t.unitLabel)}</div>` : '';
            return `<li class="preview-chip">
                <div class="preview-chip-head">
                    <span class="preview-chip-name">${this._escape(t.name)}</span>
                    <span style="display:flex;align-items:center;gap:.4rem;">
                        <span class="channel-badge ${badgeClass} channel-badge-sm">${this._escape(t.channelLabel || '')}</span>
                        <button type="button" class="target-card-remove" data-index="${i}" data-action="realizar-picker#removeTarget" aria-label="Quitar">
                            <i data-lucide="x" class="w-4 h-4"></i>
                        </button>
                    </span>
                </div>
                ${unitLine}
                ${lawLine}
            </li>`;
        }).join('');

        this.previewTarget.innerHTML = `
            <div class="preview-multi">
                <div class="targets-panel-head">
                    <i data-lucide="users" class="w-5 h-5"></i>
                    <h3>Destinatarios seleccionados (${this.selectedTargets.length})</h3>
                </div>
                <p class="preview-multi-note">PideInfo creará una solicitud independiente para cada destinatario.</p>
                <ul class="preview-multi-list">${cards}</ul>
            </div>`;
        this._reIcons();
    }

    async submit(event) {
        event.preventDefault();
        if (this.selectedTargets.length === 0) return;

        const targets = this.selectedTargets.map((t) => {
            const target = { publicBodyId: t.publicBodyId };
            if (t.regDestinationId) target.regDestinationId = t.regDestinationId;
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
            body: JSON.stringify({ targets, draftOnly: this.draftOnlyValue }),
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
