import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';

export default class extends Controller {
    connect() {
        this.tomSelect = new TomSelect(this.element, {
            placeholder: 'Seleccionar consejo de transparencia...',
            allowEmptyOption: true,
            plugins: ['clear_button'],
            render: {
                option: (data, escape) => {
                    const shortName = data.shortname || '';
                    const badge = shortName
                        ? `<span class="organism-badge">${escape(shortName)}</span>`
                        : '';
                    return `<div class="organism-option">${badge}<span>${escape(data.text)}</span></div>`;
                },
                item: (data, escape) => {
                    const shortName = data.shortname || '';
                    const badge = shortName
                        ? `<span class="organism-badge organism-badge-sm">${escape(shortName)}</span>`
                        : '';
                    return `<div class="organism-option">${badge}<span>${escape(data.text)}</span></div>`;
                },
            },
            onChange: (value) => {
                if (value) {
                    window.location.href = value;
                }
            },
        });
    }

    disconnect() {
        if (this.tomSelect) {
            this.tomSelect.destroy();
        }
    }
}
