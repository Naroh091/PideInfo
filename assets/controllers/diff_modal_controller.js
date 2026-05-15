import { Controller } from '@hotwired/stimulus';

/**
 * Mounts a modal dialog showing the diff between a previous draft snapshot
 * and the current one. Each snapshot is a {title, expone, solicita} or
 * {title, body_text} object; we render one diff section per non-empty field.
 *
 * Implementation uses an inline line-by-line diff (no third-party dependency)
 * because the content is plain Spanish prose where syntax-aware highlighting
 * adds no value.
 *
 * Values: previousValue (Object), currentValue (Object)
 */
export default class extends Controller {
    static values = {
        previous: Object,
        current: Object,
    };

    async open() {
        if (this._overlay) return;
        const overlay = document.createElement('div');
        overlay.className = 'diff-modal-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.innerHTML = `
            <div class="diff-modal-card">
                <header class="diff-modal-head">
                    <strong>Cambios respecto al borrador anterior</strong>
                    <button type="button" class="diff-modal-close" aria-label="Cerrar">×</button>
                </header>
                <div class="diff-modal-body"></div>
            </div>
        `;
        document.body.appendChild(overlay);
        this._overlay = overlay;
        overlay.querySelector('.diff-modal-close')?.addEventListener('click', () => this.close());
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) this.close();
        });
        this._onKeydown = (e) => { if (e.key === 'Escape') this.close(); };
        document.addEventListener('keydown', this._onKeydown);

        const body = overlay.querySelector('.diff-modal-body');
        const previous = this.previousValue || {};
        const current = this.currentValue || {};
        const fields = this._fieldsFor(previous, current);

        if (fields.length === 0) {
            body.textContent = 'No hay cambios visibles entre las dos versiones.';
            return;
        }

        for (const field of fields) {
            const before = String(previous[field] ?? '');
            const after = String(current[field] ?? '');
            if (before === after) continue;

            const section = document.createElement('section');
            section.className = 'diff-modal-section';
            section.innerHTML = `<h3 class="diff-modal-section-title">${this._labelFor(field)}</h3>`;
            const target = document.createElement('div');
            target.className = 'diff-modal-section-body';
            section.appendChild(target);
            body.appendChild(section);

            target.appendChild(this._fallbackDiff(before, after));
        }
    }

    close() {
        if (!this._overlay) return;
        this._overlay.remove();
        this._overlay = null;
        if (this._onKeydown) {
            document.removeEventListener('keydown', this._onKeydown);
            this._onKeydown = null;
        }
    }

    _fieldsFor(previous, current) {
        const all = new Set([...Object.keys(previous || {}), ...Object.keys(current || {})]);
        // Preserve a stable visual order.
        const order = ['title', 'expone', 'solicita', 'body_text', 'body_html'];
        const sorted = [...all].sort((a, b) => order.indexOf(a) - order.indexOf(b));
        return sorted.filter(f => f !== 'body_html' || !all.has('body_text'));
    }

    _labelFor(field) {
        switch (field) {
            case 'title': return 'Asunto';
            case 'expone': return 'EXPONE';
            case 'solicita': return 'SOLICITA';
            case 'body_text':
            case 'body_html':
                return 'Cuerpo';
            default: return field;
        }
    }

    _fallbackDiff(before, after) {
        // Naive line-by-line fallback: prefix unchanged with " ", removed with "-", added with "+".
        const a = before.split('\n');
        const b = after.split('\n');
        const max = Math.max(a.length, b.length);
        const pre = document.createElement('pre');
        pre.className = 'diff-modal-fallback';
        for (let i = 0; i < max; i++) {
            const left = a[i] ?? '';
            const right = b[i] ?? '';
            if (left === right) {
                pre.appendChild(this._line(' ', left));
            } else {
                if (left) pre.appendChild(this._line('-', left));
                if (right) pre.appendChild(this._line('+', right));
            }
        }
        return pre;
    }

    _line(sigil, text) {
        const div = document.createElement('div');
        div.className = sigil === '+' ? 'diff-line diff-line-add' : sigil === '-' ? 'diff-line diff-line-del' : 'diff-line';
        div.textContent = `${sigil} ${text}`;
        return div;
    }
}
