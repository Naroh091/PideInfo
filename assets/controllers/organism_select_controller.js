import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';

export default class extends Controller {
    connect() {
        this.tomSelect = new TomSelect(this.element, {
            allowEmptyOption: false,
            searchField: ['text', 'ccaa', 'shortname'],
            plugins: {
                dropdown_input: {},
                clear_button: {},
            },
            render: {
                option: (data, escape) => {
                    const shortName = data.shortname || '';
                    const ccaa = data.ccaa || '';
                    const badge = shortName
                        ? `<span class="organism-badge">${escape(shortName)}</span>`
                        : `<span class="organism-badge organism-badge-all">TODO</span>`;
                    const ccaaHtml = ccaa
                        ? `<div class="organism-ccaa">${escape(ccaa)}</div>`
                        : '';
                    return `<div class="organism-option">${badge}<div class="organism-option-text"><span>${escape(data.text)}</span>${ccaaHtml}</div></div>`;
                },
                item: (data, escape) => {
                    const shortName = data.shortname || '';
                    const ccaa = data.ccaa || '';
                    const badge = shortName
                        ? `<span class="organism-badge organism-badge-sm">${escape(shortName)}</span>`
                        : `<span class="organism-badge organism-badge-sm organism-badge-all">TODO</span>`;
                    const ccaaHtml = ccaa
                        ? `<div class="organism-ccaa">${escape(ccaa)}</div>`
                        : '';
                    return `<div class="organism-option">${badge}<div class="organism-option-text"><span>${escape(data.text)}</span>${ccaaHtml}</div></div>`;
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
