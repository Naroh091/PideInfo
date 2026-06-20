import { Controller } from '@hotwired/stimulus';
import TomSelect from 'tom-select';

/**
 * Draft-only picker: simple free-text + autocomplete for organism name.
 * Allows creating a new organism by typing its name (no __new__ prefix hack —
 * the value submitted is the raw name, which the controller looks up or creates).
 */
export default class extends Controller {
    static targets = ['select', 'submit'];
    static values = {
        bodies: { type: Array, default: [] },
    };

    connect() {
        const options = this.bodiesValue.map((name) => ({ value: name, text: name }));

        this.ts = new TomSelect(this.selectTarget, {
            options,
            valueField: 'value',
            labelField: 'text',
            searchField: ['text'],
            create: true,
            createOnBlur: true,
            persist: false,
            placeholder: 'Escribe el nombre del organismo...',
            plugins: { dropdown_input: {} },
            render: {
                option_create: (data, escape) =>
                    `<div class="create">Usar: <strong>${escape(data.input)}</strong></div>`,
            },
            onChange: (value) => {
                // Strip the tom-select __new__ prefix if present so the server
                // always receives the raw organism name.
                const name = typeof value === 'string'
                    ? value.replace(/^__new__:/, '').trim()
                    : '';
                // Sync to the hidden input that actually gets submitted.
                this.selectTarget.value = name;
                if (this.hasSubmitTarget) {
                    this.submitTarget.disabled = name === '';
                }
            },
        });
    }

    disconnect() {
        if (this.ts) {
            this.ts.destroy();
        }
    }
}
