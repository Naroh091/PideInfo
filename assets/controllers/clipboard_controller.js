import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['source', 'button'];
    static values = { url: String };

    async copy() {
        const text = this.sourceTarget.textContent.trim();
        await navigator.clipboard.writeText(text);

        // Visual feedback
        const icon = this.buttonTarget.querySelector('[data-lucide]');
        if (icon) {
            const originalName = icon.getAttribute('data-lucide');
            icon.setAttribute('data-lucide', 'check');
            // Re-render lucide icon
            if (window.lucide) {
                window.lucide.createIcons({ nodes: [icon] });
            }
            setTimeout(() => {
                icon.setAttribute('data-lucide', originalName);
                if (window.lucide) {
                    window.lucide.createIcons({ nodes: [icon] });
                }
            }, 2000);
        }
    }

    async generate() {
        const button = this.element.querySelector('[data-action*="generate"]');
        if (button) {
            button.disabled = true;
            button.innerHTML = '<i data-lucide="loader-2" class="w-4 h-4 mr-2 animate-spin"></i> Generando...';
        }

        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            const data = await response.json();
            if (data.success) {
                if (typeof gtag === 'function') {
                    gtag('event', 'generate_virtual_email');
                }
                // Reload to show the new email address
                window.location.reload();
            } else {
                alert(data.error || 'Error al generar el email virtual');
                if (button) {
                    button.disabled = false;
                    button.innerHTML = '<i data-lucide="wand-2" class="w-4 h-4 mr-2"></i> Generar email virtual';
                }
            }
        } catch (error) {
            alert('Error de conexión');
            if (button) {
                button.disabled = false;
                button.innerHTML = '<i data-lucide="wand-2" class="w-4 h-4 mr-2"></i> Generar email virtual';
            }
        }
    }
}
