import { Controller } from '@hotwired/stimulus';

/**
 * Composer + chat history para el flujo de "realizar".
 *   - Acciones rápidas: generar primer borrador, reescribir, sugerir.
 *   - Free text: chat libre.
 *   - Cuando la respuesta del backend `replacesCanvas`, escribe título y body
 *     en los inputs del canvas (a través de events 'input') y muestra la
 *     respuesta del asistente en el historial.
 *   - Cuando es 'suggest_ideas', pinta cada sugerencia como card con un botón
 *     "Insertar al final" que añade la sugerencia al body del canvas.
 *
 * Targets: input, history, status, generateBtn, rewriteBtn, suggestBtn
 * Values: chatUrl, titleSelector, bodySelector
 */
export default class extends Controller {
    static targets = ['input', 'history', 'status', 'generateBtn', 'rewriteBtn', 'suggestBtn', 'fab', 'panel'];
    static values = {
        chatUrl: String,
        titleSelector: { type: String, default: '#draft-title-input' },
        bodySelector: { type: String, default: '#draft-body-input' },
    };

    connect() {
        this._busy = false;
        // If the server pre-rendered a previous conversation, keep it and
        // skip the empty-state placeholder; otherwise render the welcome.
        if (!this.hasHistoryTarget || this.historyTarget.children.length === 0) {
            this._renderEmpty();
        } else {
            this.historyTarget.scrollTop = this.historyTarget.scrollHeight;
        }
        // Open by default so the asistente is discoverable on first paint.
        this._open = false;
        this.open();
    }

    open(event) {
        event?.preventDefault();
        this._open = true;
        if (this.hasFabTarget) this.fabTarget.classList.add('is-open');
        if (this.hasPanelTarget) this.panelTarget.classList.add('is-open');
        // Focus the input so typing works immediately.
        setTimeout(() => {
            if (this.hasInputTarget && !this._busy) this.inputTarget.focus();
            if (this.hasHistoryTarget) this.historyTarget.scrollTop = this.historyTarget.scrollHeight;
        }, 180);
    }

    close(event) {
        event?.preventDefault();
        this._open = false;
        if (this.hasFabTarget) this.fabTarget.classList.remove('is-open');
        if (this.hasPanelTarget) this.panelTarget.classList.remove('is-open');
    }

    /** Make sure the panel is open whenever the user fires an action. */
    _ensureOpen() {
        if (!this._open) this.open();
    }

    async sendMessage(event) {
        event?.preventDefault();
        const message = (this.inputTarget.value || '').trim();
        if (!message || this._busy) return;
        this._ensureOpen();
        this.inputTarget.value = '';
        this._appendUser(message);
        await this._dispatchAction('free_chat', message);
    }

    async generateFirst(event) {
        event?.preventDefault();
        if (this._busy) return;
        this._ensureOpen();
        const directions = (this.inputTarget.value || '').trim();
        if (directions) this._appendUser(directions);
        this.inputTarget.value = '';
        await this._dispatchAction('generate_first_draft', directions);
    }

    async rewrite(event) {
        event?.preventDefault();
        if (this._busy) return;
        this._ensureOpen();
        const directions = (this.inputTarget.value || '').trim();
        if (directions) this._appendUser(`Reescribir: ${directions}`);
        else this._appendUser('Reescribir el borrador.');
        this.inputTarget.value = '';
        await this._dispatchAction('rewrite', directions);
    }

    async suggest(event) {
        event?.preventDefault();
        if (this._busy) return;
        this._ensureOpen();
        const directions = (this.inputTarget.value || '').trim();
        if (directions) this._appendUser(`Sugerencias: ${directions}`);
        else this._appendUser('Dame ideas para mejorar este borrador.');
        this.inputTarget.value = '';
        await this._dispatchAction('suggest_ideas', directions);
    }

    async _dispatchAction(action, message) {
        this._setBusy(true);
        this._setStatus('Pensando…');

        try {
            const response = await fetch(this.chatUrlValue, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action, message }),
            });
            if (!response.ok) {
                const detail = await response.text();
                throw new Error(`HTTP ${response.status}: ${detail.slice(0, 240)}`);
            }
            const json = await response.json();
            this._handleResponse(json);
        } catch (err) {
            console.error('chat action failed', err);
            this._appendError('No se ha podido obtener una respuesta del asistente. Inténtalo de nuevo.');
        } finally {
            this._setBusy(false);
            this._setStatus('');
        }
    }

    async _handleResponse(json) {
        const { action, result, replacesCanvas } = json;

        if (replacesCanvas && result?.body_html !== undefined) {
            // Show the chat reply first so the user has something to read while
            // the typewriter runs in the canvas.
            this._appendAssistantText(result.chat_reply || 'Borrador actualizado.');
            // Hand off to the canvas controller, which animates the change and
            // emits its own settled event for the sidebar.
            await this._writeToCanvas(result.title || '', result.body_html || '');
            // Belt-and-suspenders refresh signal for the sidebar.
            this.dispatch('canvas-replaced', { detail: result });
            return;
        }

        if (action === 'suggest_ideas') {
            const suggestions = (result && result.suggestions) || [];
            if (!suggestions.length) {
                this._appendAssistantText('No tengo ideas claras para este borrador todavía. Sigue escribiendo y vuelve a pedir sugerencias.');
                return;
            }
            this._appendSuggestions(suggestions);
            return;
        }

        // free_chat
        if (result?.reply) {
            this._appendAssistantHtml(result.reply);
            return;
        }
        this._appendError('Respuesta vacía. Inténtalo de nuevo.');
    }

    async _writeToCanvas(title, bodyHtml) {
        const canvasEl = document.querySelector('[data-controller~="realizar-canvas"]');
        const canvas = canvasEl
            ? this.application.getControllerForElementAndIdentifier(canvasEl, 'realizar-canvas')
            : null;

        if (canvas && typeof canvas.replaceContent === 'function') {
            await canvas.replaceContent({ title, bodyHtml });
            return;
        }

        // Fallback if the canvas controller isn't on the page (shouldn't happen
        // in practice — keep it just so nothing breaks if the layout changes).
        const titleInput = document.querySelector(this.titleSelectorValue);
        const bodyInput = document.querySelector(this.bodySelectorValue);
        if (titleInput) {
            titleInput.value = (title || '').slice(0, 255);
            titleInput.dispatchEvent(new Event('input', { bubbles: true }));
        }
        if (bodyInput) {
            bodyInput.value = this._htmlToPlain(bodyHtml).slice(0, 3000);
            bodyInput.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }

    _appendSuggestions(suggestions) {
        if (!this.hasHistoryTarget) return;
        const cards = suggestions.map((s, i) => {
            const source = s.source ? `<span class="chat-suggest-source">${this._escape(s.source)}</span>` : '';
            return `
                <div class="chat-suggest-card" data-suggestion-index="${i}">
                    <div class="chat-suggest-head">
                        <strong>${this._escape(s.title || '')}</strong>
                        ${source}
                    </div>
                    <p class="chat-suggest-body">${this._escape(s.body || '')}</p>
                    <button type="button"
                            class="chat-suggest-insert"
                            data-action="realizar-chat#insertSuggestion"
                            data-suggestion-text="${this._escape(s.body || '')}">
                        <i data-lucide="plus" class="w-3 h-3"></i> Añadir al borrador
                    </button>
                </div>`;
        }).join('');
        this._appendBlock('assistant', `<div class="chat-suggest-grid">${cards}</div>`);
        this._reIcons();
    }

    insertSuggestion(event) {
        const btn = event.currentTarget;
        const text = btn.dataset.suggestionText || '';
        if (!text) return;
        const bodyInput = document.querySelector(this.bodySelectorValue);
        if (!bodyInput) return;
        const current = bodyInput.value || '';
        const sep = current && !current.endsWith('\n') ? '\n\n' : (current ? '\n' : '');
        const next = (current + sep + text).slice(0, 3000);
        bodyInput.value = next;
        bodyInput.dispatchEvent(new Event('input', { bubbles: true }));
        btn.disabled = true;
        btn.innerHTML = '<i data-lucide="check" class="w-3 h-3"></i> Añadido';
        this._reIcons();
    }

    _renderEmpty() {
        if (!this.hasHistoryTarget) return;
        this.historyTarget.innerHTML = `
            <div class="chat-empty">
                <i data-lucide="bot" class="w-4 h-4"></i>
                <span>Pídeme que <b>genere un primer borrador</b> a partir de tu idea, que <b>reescriba</b> el actual o que te sugiera <b>ideas</b> a partir de resoluciones similares.</span>
            </div>`;
        this._reIcons();
    }

    _appendUser(text) {
        this._clearEmpty();
        this._appendBlock('user', this._escape(text).replace(/\n/g, '<br>'));
    }

    _appendAssistantText(text) {
        this._clearEmpty();
        this._appendBlock('assistant', this._escape(text).replace(/\n/g, '<br>'));
    }

    _appendAssistantHtml(html) {
        // Allow only <b>, <i>, <br> from assistant free-chat HTML.
        const sanitized = html.replace(/<(?!\/?(b|i|br)\b)[^>]+>/gi, '');
        this._clearEmpty();
        this._appendBlock('assistant', sanitized);
    }

    _appendError(text) {
        this._clearEmpty();
        this._appendBlock('error', this._escape(text));
    }

    _appendBlock(role, innerHtml) {
        if (!this.hasHistoryTarget) return;
        const wrap = document.createElement('div');
        wrap.className = `chat-turn chat-turn-${role}`;
        wrap.innerHTML = innerHtml;
        this.historyTarget.appendChild(wrap);
        this.historyTarget.scrollTop = this.historyTarget.scrollHeight;
    }

    _clearEmpty() {
        if (!this.hasHistoryTarget) return;
        const empty = this.historyTarget.querySelector('.chat-empty');
        if (empty) empty.remove();
    }

    _setBusy(busy) {
        this._busy = busy;
        const buttons = [this.generateBtnTarget, this.rewriteBtnTarget, this.suggestBtnTarget].filter(Boolean);
        buttons.forEach((b) => { b.disabled = busy; });
        if (this.hasInputTarget) this.inputTarget.disabled = busy;
    }

    _setStatus(text) {
        if (this.hasStatusTarget) this.statusTarget.textContent = text;
    }

    _htmlToPlain(html) {
        const tmp = document.createElement('div');
        tmp.innerHTML = html.replace(/<\/p>/gi, '\n\n').replace(/<br\s*\/?>/gi, '\n');
        return (tmp.textContent || '').trim();
    }

    _escape(s) {
        return String(s ?? '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
        }[c]));
    }

    _reIcons() {
        if (window.lucide && typeof window.lucide.createIcons === 'function') {
            window.lucide.createIcons();
        }
    }
}
