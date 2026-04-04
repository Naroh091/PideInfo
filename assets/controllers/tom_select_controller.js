import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';

export default class extends Controller {
    static values = {
        create: { type: Boolean, default: false },
        createUrl: { type: String, default: '' },
        placeholder: { type: String, default: 'Selecciona...' },
        autoSubmit: { type: Boolean, default: false },
        remote: { type: String, default: '' },
    };

    connect() {
        const config = {
            placeholder: this.placeholderValue,
            allowEmptyOption: true,
            plugins: {
                dropdown_input: {},
                clear_button: {},
            },
        };

        if (this.remoteValue) {
            config.valueField = 'value';
            config.labelField = 'text';
            config.searchField = ['value', 'text'];
            config.load = (query, callback) => {
                const url = `${this.remoteValue}?q=${encodeURIComponent(query)}`;
                fetch(url)
                    .then(r => r.json())
                    .then(callback)
                    .catch(() => callback());
            };
            config.preload = true;
        }

        // Auto-detect organism-style options with data-ccaa
        const hasCcaa = this.element.querySelector('option[data-ccaa]');
        if (hasCcaa) {
            if (!config.searchField) {
                config.searchField = ['text', 'ccaa'];
            }
        }
        if (hasCcaa && !config.render) {
            config.render = {
                option: (data, escape) => {
                    const ccaa = data.ccaa || '';
                    const ccaaHtml = ccaa
                        ? `<div class="organism-ccaa">${escape(ccaa)}</div>`
                        : '';
                    return `<div class="organism-option"><div class="organism-option-text"><span>${escape(data.text)}</span>${ccaaHtml}</div></div>`;
                },
                item: (data, escape) => {
                    const ccaa = data.ccaa || '';
                    const ccaaHtml = ccaa
                        ? `<div class="organism-ccaa">${escape(ccaa)}</div>`
                        : '';
                    return `<div class="organism-option"><div class="organism-option-text"><span>${escape(data.text)}</span>${ccaaHtml}</div></div>`;
                },
            };
        }

        if (this.createValue) {
            config.create = (input, callback) => {
                return { value: `__new__:${input}`, text: input };
            };
            config.createOnBlur = true;
            config.persist = true;
            config.render = {
                option_create: (data, escape) => {
                    return `<div class="create">Crear: <strong>${escape(data.input)}</strong></div>`;
                },
            };
        }

        if (this.autoSubmitValue) {
            config.onChange = () => {
                const form = this.element.closest('form');
                if (form) form.requestSubmit();
            };
        }

        this.tomSelect = new TomSelect(this.element, config);
    }

    disconnect() {
        if (this.tomSelect) {
            this.tomSelect.destroy();
        }
    }
}
