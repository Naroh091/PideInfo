import { Controller } from '@hotwired/stimulus';

/**
 * Picker para "Realizar solicitud".
 *
 * Reemplaza la antigua cascada (nivel → ámbito → organismo → unidad) por un
 * modal de BÚSQUEDA UNIFICADA de destino:
 *   - un único buscador contra /solicitudes/nueva/realizar/destinos.json que
 *     casa por nombre Y código DIR3, mezcla unidades REG y cuerpos Portal/AGE,
 *     filtra por nivel/comunidad/provincia y pagina por offset (carga más al
 *     hacer scroll, con IntersectionObserver + botón de respaldo).
 *   - cada resultado seleccionado se acumula como destinatario a la derecha.
 *   - "Continuar" hace POST a /iniciar con `targets:[{publicBodyId, regDestinationId?}]`.
 *
 * El gate de datos personales REG (needs_profile) y el modo draft-only se
 * conservan sin cambios respecto a la versión en cascada.
 */
export default class extends Controller {
    static targets = ['addButton', 'continueButton', 'preview'];
    static values = {
        destinationsUrl: String,
        destinationFacetsUrl: String,
        initiateUrl: String,
        csrfToken: String,
        // Cuando es true el picker crea solicitudes solo-borrador: el POST lleva
        // `draftOnly: true` y el copy dice "redactaremos" en vez de "enviaremos".
        draftOnly: Boolean,
        // Tope de destinatarios (0 = sin límite). Con maxTargets=1 elegir un
        // nuevo destino sustituye al anterior en vez de acumularse — es el
        // modo del flujo público /redactar, que crea UN borrador por vez.
        maxTargets: { type: Number, default: 0 },
        // Selector CSS de inputs cuyo name/value se añade al JSON del POST de
        // inicio (p. ej. flow, resolutionResult o el token de Turnstile del
        // flujo público). Se leen en el momento del submit.
        extraFieldsSelector: { type: String, default: '' },
    };

    static PAGE_SIZE = 20;
    static DEBOUNCE_MS = 250;

    connect() {
        // [{publicBodyId, regDestinationId?, name, channel, channelLabel, levelLabel, unitLabel?, law?}]
        this.selectedTargets = [];
        this._renderTargets();
        this._updateContinueButton();
    }

    disconnect() {
        this._closeDestinationModal();
    }

    // ─────────────────────────────────────────────────────────────
    //  Modal de búsqueda de destino
    // ─────────────────────────────────────────────────────────────
    openDestinationModal() {
        if (this._modal) return;

        const overlay = document.createElement('div');
        overlay.className = 'destino-modal-overlay';
        overlay.innerHTML = `
            <div class="destino-modal" role="dialog" aria-modal="true" aria-labelledby="destino-modal-title">
                <header class="destino-modal-head">
                    <div>
                        <p class="profile-modal-eyebrow">Añadir destinatario</p>
                        <h2 class="destino-modal-title" id="destino-modal-title">Busca el organismo o la unidad de destino</h2>
                    </div>
                    <button type="button" class="profile-modal-close" data-role="close" aria-label="Cerrar">
                        <i data-lucide="x" class="w-5 h-5"></i>
                    </button>
                </header>
                <div class="destino-modal-search">
                    <i data-lucide="search" class="w-4 h-4 destino-modal-search-icon"></i>
                    <input type="search" data-role="q" autocomplete="off" spellcheck="false"
                        placeholder="Nombre o código DIR3 (p. ej. «medio ambiente Galicia» o «A12048934»)" />
                </div>
                <div class="destino-modal-facets">
                    <select data-role="nivel" aria-label="Nivel de administración"></select>
                    <select data-role="comunidad" aria-label="Comunidad autónoma"></select>
                    <select data-role="provincia" aria-label="Provincia"></select>
                </div>
                <div class="destino-modal-results" data-role="results">
                    <ul class="destino-list" data-role="list"></ul>
                    <div class="destino-status" data-role="loading" hidden>Buscando…</div>
                    <div class="destino-status" data-role="empty" hidden>No hay destinos que coincidan. Prueba con otro término o quita filtros.</div>
                    <div class="destino-sentinel" data-role="sentinel"></div>
                    <button type="button" class="destino-more" data-role="more" hidden>Cargar más</button>
                </div>
            </div>`;

        document.body.appendChild(overlay);
        document.body.style.overflow = 'hidden';
        this._reIcons();

        const $ = (role) => overlay.querySelector(`[data-role="${role}"]`);
        this._modal = {
            overlay,
            q: $('q'),
            nivel: $('nivel'),
            comunidad: $('comunidad'),
            provincia: $('provincia'),
            list: $('list'),
            loading: $('loading'),
            empty: $('empty'),
            sentinel: $('sentinel'),
            more: $('more'),
        };
        this._offset = 0;
        this._hasMore = false;
        this._loading = false;
        this._searchSeq = 0;
        this._debounce = null;

        // Cierre.
        overlay.querySelector('[data-role="close"]').addEventListener('click', () => this._closeDestinationModal());
        overlay.addEventListener('click', (ev) => { if (ev.target === overlay) this._closeDestinationModal(); });
        this._onKeydown = (ev) => { if (ev.key === 'Escape') this._closeDestinationModal(); };
        document.addEventListener('keydown', this._onKeydown);

        // Búsqueda.
        this._modal.q.addEventListener('input', () => {
            clearTimeout(this._debounce);
            this._debounce = setTimeout(() => this._search(true), this.constructor.DEBOUNCE_MS);
        });
        this._modal.nivel.addEventListener('change', () => { this._loadFacets(); this._search(true); });
        this._modal.comunidad.addEventListener('change', () => { this._loadFacets(); this._search(true); });
        this._modal.provincia.addEventListener('change', () => this._search(true));
        this._modal.more.addEventListener('click', () => this._search(false));

        // Selección por delegación.
        this._modal.list.addEventListener('click', (ev) => {
            const li = ev.target.closest('[data-candidate]');
            if (li) this._selectCandidate(JSON.parse(li.dataset.candidate));
        });

        // Carga incremental al hacer scroll.
        if ('IntersectionObserver' in window) {
            this._observer = new IntersectionObserver((entries) => {
                if (entries.some((e) => e.isIntersecting) && this._hasMore && !this._loading) {
                    this._search(false);
                }
            }, { root: this._modal.overlay.querySelector('[data-role="results"]'), rootMargin: '120px' });
            this._observer.observe(this._modal.sentinel);
        }

        this._loadFacets();
        this._search(true);
        this._modal.q.focus();
    }

    _closeDestinationModal() {
        if (!this._modal) return;
        if (this._observer) { this._observer.disconnect(); this._observer = null; }
        if (this._onKeydown) { document.removeEventListener('keydown', this._onKeydown); this._onKeydown = null; }
        clearTimeout(this._debounce);
        document.body.style.overflow = '';
        this._modal.overlay.remove();
        this._modal = null;
    }

    async _loadFacets() {
        if (!this._modal) return;
        const params = new URLSearchParams();
        if (this._modal.nivel.value) params.set('nivel', this._modal.nivel.value);
        if (this._modal.comunidad.value) params.set('comunidad', this._modal.comunidad.value);

        try {
            const json = await fetch(`${this.destinationFacetsUrlValue}?${params.toString()}`, { credentials: 'same-origin' })
                .then((r) => r.json());
            if (!this._modal) return;

            // Nivel (solo la primera vez, para no perder la selección).
            if (this._modal.nivel.options.length === 0) {
                const nivels = json.nivels || {};
                this._modal.nivel.innerHTML = '<option value="">Todos los niveles</option>'
                    + Object.entries(nivels).map(([k, v]) => `<option value="${this._escape(k)}">${this._escape(v)}</option>`).join('');
            }
            this._fillSelect(this._modal.comunidad, json.comunidades || [], 'Todas las comunidades', this._modal.comunidad.value);
            this._fillSelect(this._modal.provincia, json.provincias || [], 'Todas las provincias', this._modal.provincia.value);
        } catch (_e) { /* facetas opcionales: si fallan, la búsqueda libre sigue funcionando */ }
    }

    _fillSelect(select, options, allLabel, keep) {
        const has = options.includes(keep);
        select.innerHTML = `<option value="">${this._escape(allLabel)}</option>`
            + options.map((o) => `<option value="${this._escape(o)}">${this._escape(o)}</option>`).join('');
        select.value = has ? keep : '';
    }

    async _search(reset) {
        if (!this._modal || this._loading) return;
        if (reset) {
            this._offset = 0;
            this._modal.list.innerHTML = '';
        }
        this._loading = true;
        const seq = ++this._searchSeq;
        this._modal.loading.hidden = false;
        this._modal.empty.hidden = true;
        this._modal.more.hidden = true;

        const params = new URLSearchParams({
            q: this._modal.q.value.trim(),
            limit: String(this.constructor.PAGE_SIZE),
            offset: String(this._offset),
        });
        if (this._modal.nivel.value) params.set('nivel', this._modal.nivel.value);
        if (this._modal.comunidad.value) params.set('comunidad', this._modal.comunidad.value);
        if (this._modal.provincia.value) params.set('provincia', this._modal.provincia.value);

        try {
            const json = await fetch(`${this.destinationsUrlValue}?${params.toString()}`, { credentials: 'same-origin' })
                .then((r) => r.json());
            // Stale guard: ignora respuestas de búsquedas ya superadas.
            if (!this._modal || seq !== this._searchSeq) return;

            const items = json.items || [];
            items.forEach((c) => this._modal.list.appendChild(this._renderCandidate(c)));
            this._reIcons();

            this._hasMore = !!json.hasMore;
            this._offset = json.nextOffset || (this._offset + this.constructor.PAGE_SIZE);
            this._modal.more.hidden = !this._hasMore;
            this._modal.empty.hidden = this._modal.list.children.length > 0;
        } catch (_e) {
            if (this._modal && seq === this._searchSeq) {
                this._modal.empty.hidden = this._modal.list.children.length > 0;
            }
        } finally {
            if (this._modal && seq === this._searchSeq) {
                this._loading = false;
                this._modal.loading.hidden = true;
            } else {
                this._loading = false;
            }
        }
    }

    _renderCandidate(c) {
        const li = document.createElement('li');
        li.className = 'picker-option destino-option';
        li.setAttribute('role', 'button');
        li.tabIndex = 0;
        li.dataset.candidate = JSON.stringify(c);

        const badgeClass = c.channel === 'submit_request_transparencia' ? 'channel-badge-portal' : 'channel-badge-reg';
        const badge = c.channelLabel
            ? `<span class="channel-badge ${badgeClass} channel-badge-sm">${this._escape(c.channelLabel)}</span>` : '';
        const sem = c.semantic ? '<span class="destino-semantic" title="Sugerencia semántica"><i data-lucide="sparkles" class="w-3 h-3"></i></span>' : '';
        const context = [c.comunidad, c.provincia, c.kind === 'reg' ? c.raizName : null]
            .filter(Boolean).map((s) => this._escape(s)).join(' · ');
        const code = c.dir3 ? `<span class="picker-option-dir3">${this._escape(c.dir3)}</span>` : '';
        const law = c.applicableLaw
            ? `<span class="picker-option-meta">${this._escape(c.applicableLaw.shortCode || c.applicableLaw.name)}${c.applicableLaw.deadlineLabel ? ' · ' + this._escape(c.applicableLaw.deadlineLabel) : ''}</span>`
            : '';

        li.innerHTML = `
            <div class="picker-option-row">
                <span class="picker-option-name">${sem}${this._escape(c.displayLabel || c.name)}</span>
                ${badge}
            </div>
            <div class="picker-option-row destino-option-sub">
                ${code}
                ${context ? `<span class="picker-option-meta">${context}</span>` : ''}
            </div>
            ${law ? `<div class="picker-option-row">${law}</div>` : ''}`;

        li.addEventListener('keydown', (ev) => {
            if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); this._selectCandidate(c); }
        });
        return li;
    }

    _selectCandidate(c) {
        const entry = {
            publicBodyId: c.publicBodyId,
            name: c.name,
            channel: c.channel,
            channelLabel: c.channelLabel,
            levelLabel: c.nivelLabel,
            law: c.applicableLaw || null,
        };
        if (c.regDestinationId) {
            entry.regDestinationId = c.regDestinationId;
            entry.unitLabel = c.displayLabel;
        }
        const dup = this.selectedTargets.some((t) => t.publicBodyId === entry.publicBodyId
            && (t.regDestinationId || null) === (entry.regDestinationId || null));
        if (!dup) {
            if (this.maxTargetsValue > 0 && this.selectedTargets.length >= this.maxTargetsValue) {
                this.selectedTargets.splice(0, this.selectedTargets.length - this.maxTargetsValue + 1);
            }
            this.selectedTargets.push(entry);
        }

        this._closeDestinationModal();
        this._renderTargets();
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

    _updateContinueButton() {
        if (this.hasContinueButtonTarget) this.continueButtonTarget.disabled = this.selectedTargets.length === 0;
    }

    // ─────────────────────────────────────────────────────────────
    //  Panel derecho de destinatarios
    // ─────────────────────────────────────────────────────────────
    _renderTargets() {
        if (!this.hasPreviewTarget) return;
        const emptyBody = this.draftOnlyValue
            ? 'Pulsa "Añadir destinatario" y busca el organismo. Aquí verás la lista para los que redactaremos tu solicitud.'
            : 'Pulsa "Añadir destinatario" y busca el organismo. Aquí verás la lista a los que enviaremos tu solicitud.';
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

    // ─────────────────────────────────────────────────────────────
    //  Envío (sin cambios respecto a la cascada)
    // ─────────────────────────────────────────────────────────────
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
                await this._handleNeedsProfile(err.profileFormUrl, targets);
                return;
            }
            console.error(err);
            this.continueButtonTarget.disabled = false;
            this.continueButtonTarget.textContent = this.continueButtonTarget.dataset.previousLabel || 'Continuar';
            alert(err.userMessage || 'No se ha podido iniciar el borrador. Inténtalo de nuevo.');
        }
    }

    async _postInitiate(targets) {
        const payload = { targets, draftOnly: this.draftOnlyValue };
        if (this.extraFieldsSelectorValue) {
            document.querySelectorAll(this.extraFieldsSelectorValue).forEach((input) => {
                if (input.name) payload[input.name] = input.value;
            });
        }
        const response = await fetch(this.initiateUrlValue, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(payload),
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
        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            const e = new Error(data.message || `HTTP ${response.status}`);
            e.code = data.error || null;
            e.userMessage = data.message || null;
            throw e;
        }
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
