import { Controller } from '@hotwired/stimulus';

/**
 * Unified chat controller for the AI drafting assistant.
 *
 * - Streams server events (SSE over fetch + ReadableStream) so we can POST
 *   multipart with attachments — native EventSource is GET-only.
 * - The LLM auto-decides whether to reply with a question, generate the
 *   first draft, or rewrite the existing one (no buttons).
 * - The composer is a multi-line textarea (Shift+Enter for newline, Enter
 *   to send) and accepts file attachments that travel as ContentParts in
 *   memory only — no server-side persistence.
 * - When the assistant rewrites, the system bubble carries a "Ver cambios"
 *   button that opens a diff modal against the previous snapshot.
 *
 * Targets: fab, panel, history, input, attachInput, attachChips, sendButton, status
 * Values:  endpointUrl, isReg, canvasOutletSelector
 */
export default class extends Controller {
    static targets = ['fab', 'panel', 'history', 'input', 'attachInput', 'attachChips', 'sendButton', 'status', 'extra'];
    static values = {
        endpointUrl: String,
        isReg: { type: Boolean, default: false },
        canvasOutletSelector: { type: String, default: '' },
    };

    connect() {
        this.pendingAttachments = [];
        this._busy = false;
        this._abort = null;
        this._maxFileBytes = 4 * 1024 * 1024;
        this._maxTotalBytes = 5 * 1024 * 1024;
        if (this.hasInputTarget) {
            this._growInput();
        }
        this._scrollToBottom();
    }

    disconnect() {
        if (this._abort) {
            try { this._abort.abort(); } catch {}
        }
    }

    open() {
        if (!this.hasPanelTarget) return;
        this.panelTarget.classList.add('is-open');
        this.fabTarget?.classList.add('is-active');
        if (this.hasInputTarget) {
            requestAnimationFrame(() => this.inputTarget.focus());
        }
    }

    close() {
        if (!this.hasPanelTarget) return;
        this.panelTarget.classList.remove('is-open');
        this.fabTarget?.classList.remove('is-active');
    }

    onKeydown(event) {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            this.sendMessage(event);
        }
    }

    onInput() {
        this._growInput();
    }

    _growInput() {
        const el = this.inputTarget;
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, 14 * 16) + 'px';
    }

    onAttach(event) {
        const input = event.currentTarget;
        const files = Array.from(input?.files || []);
        for (const file of files) {
            if (file.size > this._maxFileBytes) {
                this._appendSystemBubble(`«${this._escape(file.name)}» supera el límite de 4 MB.`);
                continue;
            }
            const total = this.pendingAttachments.reduce((acc, f) => acc + f.size, 0) + file.size;
            if (total > this._maxTotalBytes) {
                this._appendSystemBubble('Conjunto de adjuntos > 5 MB; añade menos archivos.');
                break;
            }
            this.pendingAttachments.push(file);
        }
        if (input) input.value = '';
        this._renderChips();
    }

    removeAttachment(event) {
        const idx = parseInt(event.currentTarget?.dataset.index || '-1', 10);
        if (idx >= 0) {
            this.pendingAttachments.splice(idx, 1);
            this._renderChips();
        }
    }

    _renderChips() {
        if (!this.hasAttachChipsTarget) return;
        this.attachChipsTarget.innerHTML = this.pendingAttachments.map((f, i) => `
            <span class="composer-chip" title="${this._escape(f.name)}">
                <i data-lucide="paperclip" class="w-3 h-3"></i>
                <span class="composer-chip-name">${this._escape(this._shorten(f.name, 26))}</span>
                <span class="composer-chip-size">${this._humanSize(f.size)}</span>
                <button type="button" data-action="assistant-chat#removeAttachment" data-index="${i}" aria-label="Quitar">×</button>
            </span>
        `).join('');
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            try { window.lucide.createIcons(); } catch {}
        }
    }

    async sendMessage(event) {
        if (event && typeof event.preventDefault === 'function') event.preventDefault();
        if (this._busy) return;
        const message = (this.hasInputTarget ? this.inputTarget.value : '').trim();
        if (!message && this.pendingAttachments.length === 0) return;

        this._busy = true;
        this._setBusy(true);

        const userBubble = this._appendUserBubble(message, this.pendingAttachments.map(f => f.name));
        // Reset the input + chips immediately for snappy UX.
        if (this.hasInputTarget) {
            this.inputTarget.value = '';
            this._growInput();
        }
        const sentAttachments = this.pendingAttachments.slice();
        this.pendingAttachments = [];
        this._renderChips();

        // Let the host page (Alpine app, plain JS, etc.) refresh the values
        // of any `extra` hidden inputs against fresh state (e.g. current Trix
        // HTML) right before we serialize the FormData.
        this.dispatch('before-send', { detail: { extras: this.hasExtraTarget ? this.extraTargets : [] } });

        const formData = new FormData();
        formData.append('message', message);
        for (const f of sentAttachments) {
            formData.append('attachments[]', f, f.name);
        }
        // Extra hidden inputs declared by the host page (e.g. complaint mode,
        // current canvas HTML, selected document IDs).
        if (this.hasExtraTarget) {
            for (const el of this.extraTargets) {
                const name = el.getAttribute('name');
                if (!name) continue;
                formData.append(name, el.value ?? '');
            }
        }

        const assistantBubble = this._appendAssistantBubble('');
        const tokens = assistantBubble.querySelector('.chat-bubble-text');

        this._abort = new AbortController();
        let response;
        try {
            response = await fetch(this.endpointUrlValue, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData,
                signal: this._abort.signal,
                headers: { 'Accept': 'text/event-stream' },
            });
        } catch (err) {
            tokens.textContent = 'No se ha podido contactar con el asistente. Reintenta en unos segundos.';
            this._busy = false;
            this._setBusy(false);
            return;
        }

        if (!response.ok || !response.body) {
            let detail = '';
            try { detail = (await response.json())?.message || ''; } catch {}
            tokens.textContent = detail || `Error del servidor (${response.status}).`;
            this._busy = false;
            this._setBusy(false);
            return;
        }

        const reader = response.body.getReader();
        const decoder = new TextDecoder('utf-8');
        let buffer = '';
        try {
            while (true) {
                const { value, done } = await reader.read();
                if (done) break;
                buffer += decoder.decode(value, { stream: true });
                let idx;
                while ((idx = buffer.indexOf('\n\n')) !== -1) {
                    const raw = buffer.slice(0, idx);
                    buffer = buffer.slice(idx + 2);
                    this._processSseRecord(raw, tokens);
                }
            }
        } catch (err) {
            tokens.textContent = (tokens.textContent || '') + '\n[conexión interrumpida]';
        } finally {
            this._busy = false;
            this._setBusy(false);
            this._abort = null;
        }
    }

    _processSseRecord(raw, tokensEl) {
        let event = 'message';
        let data = '';
        for (const line of raw.split('\n')) {
            if (line.startsWith(':')) continue;
            if (line.startsWith('event:')) event = line.slice(6).trim();
            else if (line.startsWith('data:')) data += line.slice(5).trim();
        }
        if (!data && event !== 'done') return;
        let payload = {};
        try { payload = data ? JSON.parse(data) : {}; } catch { return; }

        switch (event) {
            case 'chat_token':
                tokensEl.textContent += String(payload.text ?? '');
                this._scrollToBottom();
                break;
            case 'decision':
                this._applyDecision(payload, tokensEl);
                break;
            case 'error':
                tokensEl.textContent = (tokensEl.textContent || '') + ' ⚠ ' + (payload.message || '');
                this._scrollToBottom();
                break;
            case 'done':
                if (!tokensEl.textContent) {
                    tokensEl.textContent = '(sin respuesta)';
                }
                break;
        }
    }

    _applyDecision({ action, draft, previous }, tokensEl) {
        if (action === 'reply' || !draft) {
            return;
        }

        const canvas = this._canvasOutlet();
        if (canvas && typeof canvas.replaceContent === 'function') {
            const payload = this.isRegValue
                ? { title: draft.title, expone: draft.expone, solicita: draft.solicita }
                : { title: draft.title, bodyHtml: draft.body_text || draft.body_html || '' };
            try { canvas.replaceContent(payload); } catch (e) { console.error(e); }
        }

        const verb = action === 'generate' ? 'generado' : 'reescrito';
        const bubble = this._appendSystemBubble(`✦ Borrador ${verb}.`);
        if (previous) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'chat-bubble-diff-btn';
            button.textContent = 'Ver cambios';
            button.dataset.controller = 'diff-modal';
            button.dataset.action = 'diff-modal#open';
            button.dataset.diffModalPreviousValue = JSON.stringify(previous);
            button.dataset.diffModalCurrentValue = JSON.stringify(draft);
            bubble.appendChild(button);
        }
    }

    _canvasOutlet() {
        if (!this.canvasOutletSelectorValue) return null;
        const el = document.querySelector(this.canvasOutletSelectorValue);
        if (!el) return null;
        const app = this.application;
        const controllerName = el.dataset.controller || '';
        const name = controllerName.split(/\s+/).find(c => c.endsWith('canvas')) || controllerName.split(/\s+/)[0];
        if (!app || !name) return null;
        return app.getControllerForElementAndIdentifier(el, name);
    }

    _appendUserBubble(message, attachmentNames) {
        const node = document.createElement('div');
        node.className = 'chat-turn chat-turn-user';
        if (message) {
            const text = document.createElement('div');
            text.className = 'chat-bubble-text';
            text.textContent = message;
            node.appendChild(text);
        }
        if (attachmentNames && attachmentNames.length) {
            const meta = document.createElement('div');
            meta.className = 'chat-bubble-attachments';
            meta.textContent = '📎 ' + attachmentNames.join(', ');
            node.appendChild(meta);
        }
        if (this.hasHistoryTarget) {
            this.historyTarget.appendChild(node);
        }
        this._scrollToBottom();
        return node;
    }

    _appendAssistantBubble(initialText) {
        const node = document.createElement('div');
        node.className = 'chat-turn chat-turn-assistant';
        const text = document.createElement('div');
        text.className = 'chat-bubble-text';
        text.textContent = initialText || '';
        node.appendChild(text);
        if (this.hasHistoryTarget) {
            this.historyTarget.appendChild(node);
        }
        this._scrollToBottom();
        return node;
    }

    _appendSystemBubble(text) {
        const node = document.createElement('div');
        node.className = 'chat-turn chat-turn-system';
        const span = document.createElement('span');
        span.textContent = text;
        node.appendChild(span);
        if (this.hasHistoryTarget) {
            this.historyTarget.appendChild(node);
        }
        this._scrollToBottom();
        return node;
    }

    _scrollToBottom() {
        if (this.hasHistoryTarget) {
            this.historyTarget.scrollTop = this.historyTarget.scrollHeight;
        }
    }

    _setBusy(busy) {
        if (this.hasSendButtonTarget) {
            this.sendButtonTarget.disabled = !!busy;
        }
        if (this.hasInputTarget) {
            this.inputTarget.disabled = !!busy;
        }
    }

    _humanSize(bytes) {
        if (bytes < 1024) return `${bytes} B`;
        if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
        return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
    }

    _shorten(s, max) {
        if (!s || s.length <= max) return s;
        return s.slice(0, max - 1) + '…';
    }

    _escape(s) {
        return String(s).replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c]));
    }
}
