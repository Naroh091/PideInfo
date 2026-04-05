import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['body', 'icon'];
    static values = { key: String };

    connect() {
        const stored = localStorage.getItem(this._storageKey());
        // Default to open if no stored state
        const isOpen = stored === null ? true : stored === 'open';

        if (!isOpen) {
            this._collapse(false);
        }
    }

    toggle() {
        const isOpen = this.bodyTarget.style.display !== 'none';
        if (isOpen) {
            this._collapse(true);
        } else {
            this._expand(true);
        }
    }

    stopProp(event) {
        event.stopPropagation();
    }

    _collapse(animate) {
        if (animate) {
            this.bodyTarget.style.overflow = 'hidden';
            this.bodyTarget.style.maxHeight = this.bodyTarget.scrollHeight + 'px';
            requestAnimationFrame(() => {
                this.bodyTarget.style.transition = 'max-height 0.2s ease-out';
                this.bodyTarget.style.maxHeight = '0';
            });
            setTimeout(() => {
                this.bodyTarget.style.display = 'none';
                this.bodyTarget.style.transition = '';
                this.bodyTarget.style.maxHeight = '';
                this.bodyTarget.style.overflow = '';
            }, 200);
        } else {
            this.bodyTarget.style.display = 'none';
        }

        if (this.hasIconTarget) {
            this.iconTarget.style.transform = 'rotate(-90deg)';
        }
        localStorage.setItem(this._storageKey(), 'closed');
    }

    _expand(animate) {
        this.bodyTarget.style.display = '';

        if (animate) {
            this.bodyTarget.style.overflow = 'hidden';
            this.bodyTarget.style.maxHeight = '0';
            requestAnimationFrame(() => {
                this.bodyTarget.style.transition = 'max-height 0.2s ease-out';
                this.bodyTarget.style.maxHeight = this.bodyTarget.scrollHeight + 'px';
            });
            setTimeout(() => {
                this.bodyTarget.style.transition = '';
                this.bodyTarget.style.maxHeight = '';
                this.bodyTarget.style.overflow = '';
            }, 200);
        }

        if (this.hasIconTarget) {
            this.iconTarget.style.transform = '';
        }
        localStorage.setItem(this._storageKey(), 'open');
    }

    _storageKey() {
        return `dash-accordion-${this.keyValue}`;
    }
}
