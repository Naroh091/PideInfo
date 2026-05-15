import { Controller } from '@hotwired/stimulus';

/**
 * Thin Stimulus shim that exposes the Trix editor used by the complaint
 * redactor to the assistant-chat controller via a uniform `replaceContent`
 * interface (matching `realizar-canvas`).
 *
 * The Alpine app on this page still owns Trix initialization, edit
 * tracking, autosave, and "Guardar" / "Presentar" — we only intercept the
 * "AI just produced a new draft" moment to push HTML into Trix so the
 * canvas reflects it. Trix's own `trix-change` event then fires and the
 * Alpine state picks it up as a normal user edit (which it semantically is,
 * since the assistant chat is now the authoring surface).
 */
export default class extends Controller {
    static targets = ['editor'];

    /**
     * @param {{title?: string, bodyHtml?: string}} payload
     */
    replaceContent({ title = '', bodyHtml = '' } = {}) {
        const editor = this._editor();
        if (!editor) {
            console.warn('complaint-canvas: trix-editor not ready, dropping replaceContent');
            return;
        }
        const html = String(bodyHtml || '');
        // loadHTML() handles selection/cursor and emits trix-change, so the
        // Alpine app sees this as a fresh edit and refreshes counters.
        try {
            editor.loadHTML(html);
        } catch (err) {
            console.error('complaint-canvas: loadHTML failed', err);
        }
    }

    _editor() {
        const el = this.hasEditorTarget
            ? this.editorTarget
            : this.element.querySelector('trix-editor');
        return el?.editor || null;
    }
}
