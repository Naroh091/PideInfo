import { Controller } from '@hotwired/stimulus';

/**
 * Shows the free-text detail only for the capacities that are quoted verbatim in the heading
 * of the written request ("Concejal del Ayuntamiento de Getafe, Grupo Municipal X"). Asking a
 * plain citizen to "detail their capacity" is noise.
 */
export default class extends Controller {
    static targets = ['select', 'detail'];

    static NEEDS_DETAIL = ['cargo_electo', 'representante_entidad'];

    connect() {
        this.toggle();
    }

    toggle() {
        const needsDetail = this.constructor.NEEDS_DETAIL.includes(this.selectTarget.value);
        this.detailTarget.classList.toggle('hidden', !needsDetail);
    }
}
