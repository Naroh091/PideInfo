import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';

/**
 * UI para los datos postales del REG.
 *   - Carga `/data/reg/options.json` (snapshot del catálogo DIR3 expuesto
 *     por reg-api.redsara.es), cacheado por el navegador.
 *   - Pinta tres tom-select: Tipo de vía (42 opciones), Provincia (52),
 *     Población (cascada según provincia).
 *   - Persiste las **etiquetas** (no los códigos INE) en hidden inputs
 *     para que el agente las inyecte literalmente en los mat-select del
 *     REG sin mapping intermedio.
 *
 * Targets (todos hidden inputs):
 *   streetType, province, municipality
 *
 * Values:
 *   optionsUrl → URL del JSON (default `/data/reg/options.json`)
 */
export default class extends Controller {
    static targets = ['streetType', 'province', 'municipality'];
    static values = {
        optionsUrl: { type: String, default: '/data/reg/options.json' },
    };

    async connect() {
        this._mountPoints = this._buildMountPoints();
        try {
            this.options = await this._loadOptions();
        } catch (err) {
            console.error('reg-address-form: no se pudo cargar /data/reg/options.json', err);
            this._renderLoadError();
            return;
        }

        this._mountStreetType();
        this._mountProvince();
        this._mountMunicipality();
    }

    disconnect() {
        for (const ts of [this._streetTypeTs, this._provinceTs, this._municipalityTs]) {
            if (ts) ts.destroy();
        }
    }

    async _loadOptions() {
        const r = await fetch(this.optionsUrlValue, { credentials: 'same-origin' });
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        return r.json();
    }

    _buildMountPoints() {
        const make = (target) => {
            const select = document.createElement('select');
            select.autocomplete = 'off';
            select.id = `${target.id || target.name}_picker`;
            target.insertAdjacentElement('afterend', select);
            return select;
        };
        return {
            streetType: make(this.streetTypeTarget),
            province: make(this.provinceTarget),
            municipality: make(this.municipalityTarget),
        };
    }

    _mountStreetType() {
        const items = this.options.streetTypes.map((o) => ({ value: o.label, text: o.label }));
        const initial = this.streetTypeTarget.value || '';
        this._streetTypeTs = new TomSelect(this._mountPoints.streetType, {
            valueField: 'value', labelField: 'text', searchField: ['text'],
            options: items,
            placeholder: 'Tipo de vía',
            allowEmptyOption: false,
            plugins: { dropdown_input: {} },
            onChange: (value) => this._writeBack(this.streetTypeTarget, value || ''),
        });
        if (initial) this._streetTypeTs.setValue(initial, true);
    }

    _mountProvince() {
        // value is the LABEL because that's what we persist; we keep the INE
        // code in `data-code` so the municipality cascade can lookup `townsByProvince`.
        this.provinceCodeByLabel = new Map(this.options.provinces.map((p) => [p.label, p.value]));
        const items = this.options.provinces
            .slice()
            .sort((a, b) => a.label.localeCompare(b.label, 'es'))
            .map((p) => ({ value: p.label, text: p.label, code: p.value }));

        const initial = this.provinceTarget.value || '';
        this._provinceTs = new TomSelect(this._mountPoints.province, {
            valueField: 'value', labelField: 'text', searchField: ['text'],
            options: items,
            placeholder: 'Provincia',
            allowEmptyOption: false,
            plugins: { dropdown_input: {} },
            onChange: (value) => {
                this._writeBack(this.provinceTarget, value || '');
                this._refreshMunicipalities(value || '');
            },
        });
        if (initial) this._provinceTs.setValue(initial, true);
    }

    _mountMunicipality() {
        const initialMunicipality = this.municipalityTarget.value || '';
        this._municipalityTs = new TomSelect(this._mountPoints.municipality, {
            valueField: 'value', labelField: 'text', searchField: ['text'],
            options: [],
            placeholder: 'Selecciona primero la provincia',
            allowEmptyOption: false,
            plugins: { dropdown_input: {} },
            onChange: (value) => this._writeBack(this.municipalityTarget, value || ''),
        });

        // If the form already had a province selected on render, populate the
        // municipality list right away so the previously-saved value is shown.
        const initialProvince = this.provinceTarget.value || '';
        if (initialProvince) {
            this._refreshMunicipalities(initialProvince, initialMunicipality);
        }
    }

    _refreshMunicipalities(provinceLabel, presetMunicipality = '') {
        if (!this._municipalityTs) return;
        this._municipalityTs.clear(true);
        this._municipalityTs.clearOptions();

        const code = this.provinceCodeByLabel.get(provinceLabel);
        const towns = (code && this.options.townsByProvince[code]) || [];
        const items = towns.map((t) => ({ value: t.name, text: t.name, code: t.code }));
        if (items.length === 0) {
            this._municipalityTs.settings.placeholder = 'Sin municipios disponibles';
        } else {
            this._municipalityTs.settings.placeholder = 'Población';
            this._municipalityTs.addOption(items);
            this._municipalityTs.refreshOptions(false);
        }

        if (presetMunicipality && items.find((i) => i.value === presetMunicipality)) {
            this._municipalityTs.setValue(presetMunicipality, true);
        } else {
            this._writeBack(this.municipalityTarget, '');
        }
    }

    _writeBack(target, value) {
        target.value = value;
        target.dispatchEvent(new Event('input', { bubbles: true }));
        target.dispatchEvent(new Event('change', { bubbles: true }));
    }

    _renderLoadError() {
        const banner = document.createElement('p');
        banner.className = 'reg-address-error';
        banner.textContent = 'No se ha podido cargar el catálogo de direcciones del REG. Recarga la página o contacta con soporte.';
        this.element.prepend(banner);
    }
}
