import { Controller } from '@hotwired/stimulus';

/**
 * Adds a "X / max" counter underneath an input/textarea with a maxlength.
 * Goes amber at 90% and red at 100%. Use:
 *   data-controller="character-counter"
 *   data-character-counter-max-value="3000"
 */
export default class extends Controller {
    static values = {
        max: { type: Number, default: 0 },
    };

    connect() {
        if (this.maxValue <= 0) return;
        this.counter = document.createElement('div');
        this.counter.className = 'character-counter text-xs text-slate-400 mt-1 text-right';
        this.element.parentNode.insertBefore(this.counter, this.element.nextSibling);
        this.update = this.update.bind(this);
        this.element.addEventListener('input', this.update);
        this.update();
    }

    disconnect() {
        if (this.update) this.element.removeEventListener('input', this.update);
        if (this.counter && this.counter.parentNode) this.counter.parentNode.removeChild(this.counter);
    }

    update() {
        const len = (this.element.value || '').length;
        this.counter.textContent = `${len} / ${this.maxValue}`;
        this.counter.classList.toggle('text-amber-600', len >= this.maxValue * 0.9 && len < this.maxValue);
        this.counter.classList.toggle('text-red-600', len >= this.maxValue);
    }
}
