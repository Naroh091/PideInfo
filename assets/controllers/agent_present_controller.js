import { Controller } from '@hotwired/stimulus';

/*
 * Triggers the agent presentation flow:
 * 1. POSTs the complaint endpoint with {mode}.
 * 2. Navigates to pideinfo:// to wake the agent.
 * 3. Polls /api/agent/tasks/{id} every 2s, updates the status pill.
 * 4. After 5s without progress, surfaces the fallback panel.
 */
export default class extends Controller {
    static values = {
        presentUrl: String,
        pdfUrl: String,
    };
    static targets = ['mode', 'status', 'fallback', 'fallbackPdf', 'retryLink'];

    async submit(event) {
        if (event && typeof event.preventDefault === 'function') event.preventDefault();
        const mode = this.modeTargets.find(t => t.checked)?.value;
        if (!mode) return;

        this.setStatus('Creando tarea…');
        this.fallbackTarget.classList.add('hidden');

        let task;
        try {
            const response = await fetch(this.presentUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ mode }),
            });
            task = await response.json();
            if (!response.ok) {
                this.setStatus('Error: ' + (task.error || 'no se pudo crear la tarea'));
                return;
            }
        } catch (e) {
            this.setStatus('Error de red al crear la tarea');
            return;
        }

        this.setStatus('Despertando al agente…');
        window.location.href = task.schemeUrl;

        const stillPendingAt = Date.now() + 5000;
        if (this.hasRetryLinkTarget) this.retryLinkTarget.href = task.schemeUrl;
        if (this.hasFallbackPdfTarget) this.fallbackPdfTarget.href = this.pdfUrlValue || '';

        const tick = async () => {
            try {
                const r = await fetch(task.statusUrl, { headers: { 'Accept': 'application/json' } });
                if (!r.ok) throw new Error('status ' + r.status);
                const t = await r.json();
                this.setStatus(this.label(t.status));
                if (Date.now() > stillPendingAt && t.status === 'pending') {
                    this.fallbackTarget.classList.remove('hidden');
                }
                if (t.status === 'done' || t.status === 'failed') return;
            } catch (_) { /* keep polling */ }
            setTimeout(tick, 2000);
        };
        setTimeout(tick, 1500);
    }

    setStatus(text) {
        if (this.hasStatusTarget) this.statusTarget.textContent = text;
    }

    label(status) {
        switch (status) {
            case 'pending': return '🟡 Esperando al agente…';
            case 'claimed': return '🔵 Agente conectado, preparando…';
            case 'in_progress': return '🔵 Agente trabajando…';
            case 'done': return '🟢 Agente lanzado. Termina la presentación en el navegador.';
            case 'failed': return '🔴 Falló — pulsa Reintentar.';
            default: return status;
        }
    }
}
