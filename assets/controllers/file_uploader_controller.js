import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'preview', 'status', 'modal', 'dropzone', 'keywordMatchModal', 'keywordMatchList', 'orphanLinkModal'];
    static values = {
        url: String,
        processUrl: { type: String, default: '/documentos/procesar' },
        keywordMatchUrl: { type: String, default: '/documentos/keyword-matched' },
        statusUrl: { type: String, default: '/documentos/status' },
        searchRequestsUrl: { type: String, default: '/solicitudes/buscar' },
        orphanedUrl: { type: String, default: '/documentos/orphaned' },
        accessRequestId: { type: String, default: '' },
        documentsFragmentUrl: { type: String, default: '' }
    };

    connect() {
        this.uploadedDocuments = [];
        this.pollTimeout = null;
        this.statusPollTimeout = null;
        this.pendingDocumentIds = new Set();
    }

    disconnect() {
        if (this.pollTimeout) {
            clearTimeout(this.pollTimeout);
        }
        if (this.statusPollTimeout) {
            clearTimeout(this.statusPollTimeout);
        }
    }

    // Stimulus actions for dropzone
    dragOver(event) {
        event.preventDefault();
        event.stopPropagation();
        if (this.hasDropzoneTarget) {
            this.dropzoneTarget.classList.add('dragover');
        }
    }

    dragLeave(event) {
        event.preventDefault();
        event.stopPropagation();
        if (this.hasDropzoneTarget) {
            this.dropzoneTarget.classList.remove('dragover');
        }
    }

    drop(event) {
        event.preventDefault();
        event.stopPropagation();
        if (this.hasDropzoneTarget) {
            this.dropzoneTarget.classList.remove('dragover');
        }

        const files = event.dataTransfer.files;
        if (files.length > 0) {
            this.uploadFiles(files);
        }
    }

    clickToUpload(event) {
        // Don't trigger if clicking a button or already on input
        if (event.target.closest('button') || event.target.closest('span.btn') || event.target.closest('input')) {
            return;
        }
        if (this.hasInputTarget) {
            this.inputTarget.click();
        }
    }

    selectFiles(event) {
        const files = event.target.files;
        if (files.length > 0) {
            this.uploadFiles(files);
        }
        // Reset input so same file can be selected again
        event.target.value = '';
    }

    async uploadFiles(files) {
        // Reset for new batch
        this.uploadedDocuments = [];
        const fileCount = files.length;

        console.log('uploadFiles called with', fileCount, 'files');

        for (const file of files) {
            await this.uploadFile(file, fileCount > 1);
        }

        console.log('All uploads done, uploadedDocuments:', this.uploadedDocuments.length);

        // After all uploads, ask if related (only if multiple files)
        if (this.uploadedDocuments.length > 1) {
            console.log('Showing related modal');
            this.showRelatedModal();
        }
        // Single file is already processed by server (deferProcessing=false)
    }

    async uploadFile(file, deferProcessing = false) {
        const formData = new FormData();
        formData.append('file', file);

        if (this.accessRequestIdValue) {
            formData.append('accessRequestId', this.accessRequestIdValue);
        }

        // Don't auto-process if we're doing multi-upload
        if (deferProcessing) {
            formData.append('deferProcessing', '1');
        }

        // Show uploading status
        const shortName = file.name.length > 40 ? file.name.substring(0, 37) + '...' : file.name;
        this.showStatus(`Subiendo "${shortName}"...`, 'info');

        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const result = await response.json();

            if (response.ok && result.success) {
                const uploadedShortName = file.name.length > 40 ? file.name.substring(0, 37) + '...' : file.name;
                this.showStatus(`"${uploadedShortName}" subido correctamente`, 'success');
                this.addFileToPreview(result.document);
                this.uploadedDocuments.push(result.document);

                if (typeof gtag === 'function') {
                    gtag('event', 'file_upload', {
                        event_category: this.accessRequestIdValue ? 'request_details' : 'home',
                        event_label: file.name,
                    });
                }

                // Dispatch custom event for other components to react
                this.dispatch('uploaded', { detail: result.document });

                // Start polling for document status (for single file uploads)
                if (!deferProcessing) {
                    this.startPollingForStatus();
                }
            } else {
                this.showStatus(result.error || 'Error al subir el archivo', 'danger');
            }
        } catch (error) {
            console.error('Upload error:', error);
            this.showStatus('Error de conexion al subir el archivo', 'danger');
        }
    }

    showRelatedModal() {
        console.log('showRelatedModal called, hasModalTarget:', this.hasModalTarget);
        if (this.hasModalTarget) {
            console.log('Modal element:', this.modalTarget);
            this.modalTarget.classList.remove('hidden');
        } else {
            // Fallback: try to find by ID
            const modal = document.getElementById('relatedDocsModal');
            console.log('Fallback modal element:', modal);
            if (modal) {
                modal.classList.remove('hidden');
            }
        }
    }

    closeModal() {
        if (this.hasModalTarget) {
            this.modalTarget.classList.add('hidden');
        }
    }

    processAsRelated() {
        this.closeModal();
        this.processDocuments(this.uploadedDocuments.map(d => d.id), true);
    }

    processAsSeparate() {
        this.closeModal();
        this.processDocuments(this.uploadedDocuments.map(d => d.id), false);
    }

    async processDocuments(documentIds, asRelated) {
        try {
            this.showStatus(
                asRelated
                    ? 'Enviando documentos para analisis conjunto...'
                    : 'Enviando documentos para analisis...',
                'info'
            );

            const response = await fetch(this.processUrlValue, {
                method: 'POST',
                body: JSON.stringify({ documentIds, asRelated }),
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const result = await response.json();

            if (response.ok && result.success) {
                this.showStatus(
                    asRelated
                        ? 'Documentos enviados para analisis conjunto. Se procesaran en segundo plano.'
                        : 'Documentos enviados para analisis individual. Se procesaran en segundo plano.',
                    'success'
                );

                // Start polling for document status after batch processing is triggered
                this.startPollingForStatus();
            } else {
                this.showStatus(result.error || 'Error al procesar documentos', 'danger');
            }
        } catch (error) {
            console.error('Process error:', error);
            this.showStatus('Error al enviar documentos para procesamiento', 'danger');
        }
    }

    showStatus(message, type) {
        if (this.hasStatusTarget) {
            const iconMap = {
                success: 'check-circle',
                danger: 'alert-circle',
                warning: 'alert-triangle',
                info: 'loader'
            };
            const icon = iconMap[type] || 'info';
            const alertClass = type === 'danger' ? 'alert-danger' : `alert-${type}`;

            this.statusTarget.innerHTML = `
                <div class="alert ${alertClass} animate-fade-in overflow-hidden" role="alert">
                    <i data-lucide="${icon}" class="w-5 h-5 flex-shrink-0 ${type === 'info' ? 'animate-spin' : ''}"></i>
                    <span class="min-w-0 truncate">${message}</span>
                </div>
            `;

            // Re-initialize Lucide icons for the new content
            if (window.lucide) {
                window.lucide.createIcons();
            }
        }
    }

    addFileToPreview(doc) {
        if (this.hasPreviewTarget) {
            const item = document.createElement('div');
            item.className = 'flex items-center justify-between p-3 bg-slate-50 rounded-xl border-2 border-transparent';
            item.dataset.documentPreviewId = doc.id;
            item.innerHTML = `
                <div class="flex items-center gap-3 min-w-0">
                    <div class="w-10 h-10 rounded-lg bg-primary-100 flex items-center justify-center flex-shrink-0 processing-icon">
                        <i data-lucide="loader-2" class="w-5 h-5 text-primary-600 animate-spin"></i>
                    </div>
                    <div class="min-w-0">
                        <p class="font-medium text-slate-800 truncate">${doc.name}</p>
                        <p class="text-sm text-slate-500">${doc.size}</p>
                    </div>
                </div>
                <div class="flex items-center gap-2 flex-shrink-0">
                    <span class="badge badge-primary flex items-center gap-1.5" data-document-badge-id="${doc.id}">
                        <span class="w-2 h-2 bg-white rounded-full animate-pulse"></span>
                        Procesando...
                    </span>
                </div>
            `;
            this.previewTarget.appendChild(item);

            // Add a subtle pulsing border animation
            item.classList.add('animate-processing');

            // Track this document as pending
            this.pendingDocumentIds.add(doc.id);

            // Re-initialize Lucide icons for the new content
            if (window.lucide) {
                window.lucide.createIcons();
            }
        }
    }

    // Polling for document processing status
    startPollingForStatus() {
        // Clear any existing poll
        if (this.statusPollTimeout) {
            clearTimeout(this.statusPollTimeout);
        }

        // Poll after a short delay to give the async processor time to run
        this.statusPollTimeout = setTimeout(() => this.checkDocumentStatus(), 3000);
    }

    async checkDocumentStatus() {
        // Get all pending document IDs
        const documentIds = Array.from(this.pendingDocumentIds);

        if (documentIds.length === 0) {
            return;
        }

        try {
            const response = await fetch(this.statusUrlValue, {
                method: 'POST',
                body: JSON.stringify({ documentIds }),
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (response.ok) {
                const data = await response.json();
                let anyProcessed = false;
                let anyKeywordMatched = false;

                // Collect processed documents for notification
                const processedDocs = [];

                // Update badges for processed documents
                for (const doc of data.documents) {
                    if (doc.processed) {
                        this.pendingDocumentIds.delete(doc.id);
                        anyProcessed = true;
                        processedDocs.push(doc);

                        if (doc.matchMethod === 'keywords') {
                            anyKeywordMatched = true;
                        }

                        // Remove from preview after a short delay (to show the success state briefly)
                        this.removeDocumentFromPreview(doc.id);
                    }
                }

                // Check for orphan documents (processed without accessRequest)
                const orphanDocs = processedDocs.filter(d => d.processed && !d.accessRequest && !d.error);

                // Show notification if documents were processed
                if (anyProcessed) {
                    this.showProcessingCompleteNotification(processedDocs);

                    // Refresh the document list on the request detail page
                    this.refreshDocumentList();

                    // Trigger refresh of LiveComponents on the page
                    this.triggerDashboardRefresh();
                }

                // Show orphan linking modal for the first orphan document
                if (orphanDocs.length > 0 && !this.accessRequestIdValue) {
                    // Queue orphan docs and show modal for the first one
                    this._orphanQueue = orphanDocs.slice(1);
                    this.showOrphanLinkingModal(orphanDocs[0]);
                }

                // Show keyword match modal if any documents matched by keywords
                if (anyKeywordMatched) {
                    await this.checkForKeywordMatches();
                }

                // Continue polling if there are still pending documents
                if (this.pendingDocumentIds.size > 0) {
                    this.statusPollTimeout = setTimeout(() => this.checkDocumentStatus(), 3000);
                }
            }
        } catch (error) {
            console.error('Error checking document status:', error);
            // Retry on error if there are still pending documents
            if (this.pendingDocumentIds.size > 0) {
                this.statusPollTimeout = setTimeout(() => this.checkDocumentStatus(), 5000);
            }
        }
    }

    removeDocumentFromPreview(docId) {
        const previewItem = document.querySelector(`[data-document-preview-id="${docId}"]`);
        if (previewItem) {
            // Add fade out animation then remove
            previewItem.classList.add('animate-fade-out');
            setTimeout(() => previewItem.remove(), 300);
        }
    }

    showProcessingCompleteNotification(processedDocs) {
        const hasErrors = processedDocs.some(d => d.error);
        const hasKeywordMatch = processedDocs.some(d => d.matchMethod === 'keywords');

        // Find documents linked to access requests
        const docsWithRequests = processedDocs.filter(d => d.accessRequest);
        const newRequests = docsWithRequests.filter(d => d.accessRequest?.isNew);
        const updatedRequests = docsWithRequests.filter(d => !d.accessRequest?.isNew);

        // Show alerts for new/updated requests
        if (newRequests.length > 0) {
            // Group by request (multiple docs can belong to same request)
            const uniqueRequests = this.getUniqueRequests(newRequests);
            uniqueRequests.forEach(req => {
                this.showRequestAlert(req, 'created');
            });
        }

        if (updatedRequests.length > 0) {
            const uniqueRequests = this.getUniqueRequests(updatedRequests);
            uniqueRequests.forEach(req => {
                this.showRequestAlert(req, 'updated');
            });
        }

        // Show error notification if there were errors
        if (hasErrors) {
            const errorDocs = processedDocs.filter(d => d.error);
            errorDocs.forEach(doc => {
                this.showToast(`Error procesando "${doc.name}": ${doc.error}`, 'danger');
            });
        }

        // Show keyword match notification
        if (hasKeywordMatch) {
            this.showToast('Algunos documentos requieren verificar la asociación sugerida', 'warning');
        }
    }

    getUniqueRequests(docs) {
        const seen = new Set();
        const unique = [];
        for (const doc of docs) {
            if (doc.accessRequest && !seen.has(doc.accessRequest.id)) {
                seen.add(doc.accessRequest.id);
                unique.push(doc.accessRequest);
            }
        }
        return unique;
    }

    showRequestAlert(request, action) {
        const isNew = action === 'created';
        const alertClass = isNew ? 'alert-success' : 'alert-info';
        const icon = isNew ? 'file-plus' : 'file-check';
        const title = isNew ? 'Nueva solicitud creada' : 'Solicitud actualizada';

        const alertHtml = `
            <div class="alert ${alertClass} animate-slide-up">
                <i data-lucide="${icon}" class="w-5 h-5 flex-shrink-0"></i>
                <div class="flex-1 min-w-0">
                    <p class="font-medium">${title}</p>
                    <p class="text-sm truncate">${request.title}</p>
                </div>
                <a href="${request.url}" target="_blank" rel="noopener" class="btn btn-sm ${isNew ? 'btn-success' : 'btn-primary'}">
                    <i data-lucide="external-link" class="w-4 h-4 mr-1"></i>
                    Ver solicitud
                </a>
                <button type="button" class="btn btn-ghost btn-sm" onclick="this.closest('.alert').remove()">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>
        `;

        // Insert into global alerts container at top of page
        const globalContainer = document.getElementById('global-alerts-container');
        if (globalContainer) {
            globalContainer.insertAdjacentHTML('beforeend', alertHtml);

            // Re-initialize Lucide icons
            if (window.lucide) {
                window.lucide.createIcons();
            }

            // Scroll to top to show the alert
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    }

    showToast(message, type = 'info') {
        // Create toast container if it doesn't exist
        let container = document.getElementById('toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toast-container';
            container.className = 'fixed bottom-4 right-4 z-50 flex flex-col gap-2';
            document.body.appendChild(container);
        }

        const iconMap = {
            success: 'check-circle',
            danger: 'alert-circle',
            warning: 'alert-triangle',
            info: 'info'
        };
        const icon = iconMap[type] || 'info';

        const bgMap = {
            success: 'bg-green-600',
            danger: 'bg-red-600',
            warning: 'bg-amber-600',
            info: 'bg-blue-600'
        };
        const bgClass = bgMap[type] || 'bg-blue-600';

        const toast = document.createElement('div');
        toast.className = `${bgClass} text-white px-4 py-3 rounded-lg shadow-lg flex items-center gap-3 animate-slide-in-right max-w-sm`;
        toast.innerHTML = `
            <i data-lucide="${icon}" class="w-5 h-5 flex-shrink-0"></i>
            <span class="text-sm font-medium">${message}</span>
            <button type="button" class="ml-auto hover:opacity-75" onclick="this.parentElement.remove()">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        `;

        container.appendChild(toast);

        // Re-initialize Lucide icons
        if (window.lucide) {
            window.lucide.createIcons();
        }

        // Auto-remove after 5 seconds
        setTimeout(() => {
            toast.classList.add('animate-fade-out');
            setTimeout(() => toast.remove(), 300);
        }, 5000);
    }

    async refreshDocumentList() {
        const url = this.documentsFragmentUrlValue;
        if (!url) return;

        const container = document.getElementById('documents-list');
        if (!container) return;

        try {
            const resp = await fetch(url, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (resp.ok) {
                container.innerHTML = await resp.text();
                if (window.lucide) window.lucide.createIcons();
            }
        } catch (e) {
            console.error('Error refreshing document list:', e);
        }
    }

    triggerDashboardRefresh() {
        // Dispatch a custom event that LiveComponents listen to via data-action attribute
        // The components are configured to call live#$render when this event fires
        window.dispatchEvent(new CustomEvent('documents:processed'));
    }

    async checkForKeywordMatches() {
        try {
            const response = await fetch(this.keywordMatchUrlValue, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            if (response.ok) {
                const data = await response.json();
                if (data.documents && data.documents.length > 0) {
                    this.showKeywordMatchModal(data.documents);
                }
            }
        } catch (error) {
            console.error('Error checking keyword matches:', error);
        }
    }

    showKeywordMatchModal(documents) {
        const modal = this.hasKeywordMatchModalTarget
            ? this.keywordMatchModalTarget
            : document.getElementById('keywordMatchModal');

        if (!modal) {
            console.warn('Keyword match modal not found');
            return;
        }

        const list = this.hasKeywordMatchListTarget
            ? this.keywordMatchListTarget
            : document.getElementById('keywordMatchList');

        if (list) {
            list.innerHTML = documents.map(doc => `
                <div class="p-4 rounded-xl bg-amber-50 border border-amber-200" data-document-id="${doc.id}">
                    <div class="flex items-start gap-3">
                        <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center flex-shrink-0">
                            <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-600"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="font-medium text-slate-800 truncate">${doc.name}</p>
                            <p class="text-sm text-slate-600 mt-1">
                                Tipo: <span class="font-medium">${doc.typeLabel}</span>
                            </p>
                            ${doc.accessRequest ? `
                                <p class="text-sm text-amber-700 mt-2">
                                    <i data-lucide="link" class="w-3 h-3 inline mr-1"></i>
                                    Asociado a: <span class="font-medium">${doc.accessRequest.title}</span>
                                </p>
                            ` : ''}
                            <div class="flex gap-2 mt-3">
                                <button type="button"
                                        class="btn btn-sm btn-success"
                                        data-action="click->file-uploader#confirmKeywordMatch"
                                        data-document-id="${doc.id}">
                                    <i data-lucide="check" class="w-3 h-3 mr-1"></i>
                                    Confirmar
                                </button>
                                <button type="button"
                                        class="btn btn-sm btn-outline"
                                        data-action="click->file-uploader#rejectKeywordMatch"
                                        data-document-id="${doc.id}">
                                    <i data-lucide="x" class="w-3 h-3 mr-1"></i>
                                    Rechazar
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `).join('');

            // Re-initialize Lucide icons
            if (window.lucide) {
                window.lucide.createIcons();
            }
        }

        modal.classList.remove('hidden');
    }

    closeKeywordMatchModal() {
        const modal = this.hasKeywordMatchModalTarget
            ? this.keywordMatchModalTarget
            : document.getElementById('keywordMatchModal');

        if (modal) {
            modal.classList.add('hidden');
        }
    }

    async confirmKeywordMatch(event) {
        const documentId = event.currentTarget.dataset.documentId;
        await this.updateKeywordMatch(documentId, 'confirm');
    }

    async rejectKeywordMatch(event) {
        const documentId = event.currentTarget.dataset.documentId;
        await this.updateKeywordMatch(documentId, 'reject');
    }

    async updateKeywordMatch(documentId, action) {
        const url = `/documentos/${documentId}/${action}-match`;

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Content-Type': 'application/json'
                }
            });

            if (response.ok) {
                // Remove the document from the modal list
                const docElement = document.querySelector(`[data-document-id="${documentId}"]`);
                if (docElement) {
                    docElement.remove();
                }

                // Check if there are any remaining documents
                const list = this.hasKeywordMatchListTarget
                    ? this.keywordMatchListTarget
                    : document.getElementById('keywordMatchList');

                if (list && list.children.length === 0) {
                    this.closeKeywordMatchModal();
                    this.showStatus(
                        action === 'confirm'
                            ? 'Asociacion confirmada correctamente'
                            : 'Asociacion rechazada. El documento queda sin asignar.',
                        action === 'confirm' ? 'success' : 'warning'
                    );
                }
            }
        } catch (error) {
            console.error('Error updating keyword match:', error);
            this.showStatus('Error al actualizar la asociacion', 'danger');
        }
    }

    // === Orphan Document Linking ===

    showOrphanLinkingModal(doc) {
        const modal = this.hasOrphanLinkModalTarget
            ? this.orphanLinkModalTarget
            : document.getElementById('orphanLinkModal');

        if (!modal) return;

        this._currentOrphanDoc = doc;

        // Set the iframe src for PDF preview (use inline=1 to embed instead of download)
        // #navpanes=0 hides the browser PDF viewer sidebar (thumbnails/bookmarks)
        const viewer = modal.querySelector('#orphanDocViewer');
        if (viewer && doc.downloadUrl) {
            const separator = doc.downloadUrl.includes('?') ? '&' : '?';
            viewer.src = doc.downloadUrl + separator + 'inline=1#navpanes=0';
        }

        // Set document info
        const docTitle = modal.querySelector('#orphanDocTitle');
        if (docTitle) {
            docTitle.textContent = doc.name || doc.originalFilename || 'Documento';
        }
        const docType = modal.querySelector('#orphanDocType');
        if (docType) {
            docType.textContent = doc.typeLabel || '';
        }

        // Load suggested and all requests
        this.loadOrphanRequests(doc);

        modal.classList.remove('hidden');
    }

    closeOrphanModal() {
        const modal = this.hasOrphanLinkModalTarget
            ? this.orphanLinkModalTarget
            : document.getElementById('orphanLinkModal');

        if (modal) {
            modal.classList.add('hidden');
            const viewer = modal.querySelector('#orphanDocViewer');
            if (viewer) viewer.src = '';
        }
        this._currentOrphanDoc = null;
    }

    _parseSpanishDate(dateStr) {
        if (!dateStr) return null;

        // Try standard Date parsing first (works for ISO formats like "2026-01-16")
        const standard = new Date(dateStr);
        if (!isNaN(standard.getTime())) return standard;

        // Parse Spanish date formats: "16 de enero de 2026", "16 enero 2026", etc.
        const months = {
            'enero': 0, 'febrero': 1, 'marzo': 2, 'abril': 3,
            'mayo': 4, 'junio': 5, 'julio': 6, 'agosto': 7,
            'septiembre': 8, 'octubre': 9, 'noviembre': 10, 'diciembre': 11
        };

        const match = dateStr.toLowerCase().match(/(\d{1,2})\s+(?:de\s+)?(\w+)\s+(?:de\s+)?(\d{4})/);
        if (match) {
            const day = parseInt(match[1], 10);
            const month = months[match[2]];
            const year = parseInt(match[3], 10);
            if (month !== undefined) {
                return new Date(year, month, day);
            }
        }

        // Try "dd/mm/yyyy" format
        const slashMatch = dateStr.match(/(\d{1,2})\/(\d{1,2})\/(\d{4})/);
        if (slashMatch) {
            return new Date(parseInt(slashMatch[3], 10), parseInt(slashMatch[2], 10) - 1, parseInt(slashMatch[1], 10));
        }

        return null;
    }

    async loadOrphanRequests(doc) {
        const suggestedContainer = document.getElementById('suggestedRequests');
        const allContainer = document.getElementById('allRequests');

        // Build suggestion filters from AI metadata
        const publicBody = doc.publicBodyName || '';
        const docDate = doc.documentDate || '';

        let suggestedResults = [];
        const parsedDate = this._parseSpanishDate(docDate);

        // Fetch by date range (most reliable signal)
        if (parsedDate) {
            const from = new Date(parsedDate);
            from.setDate(from.getDate() - 4);
            const to = new Date(parsedDate);
            to.setDate(to.getDate() + 4);
            const dateParams = new URLSearchParams({
                dateFrom: from.toISOString().split('T')[0],
                dateTo: to.toISOString().split('T')[0],
            });
            try {
                const resp = await fetch(`${this.searchRequestsUrlValue}?${dateParams.toString()}`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                if (resp.ok) {
                    const data = await resp.json();
                    suggestedResults = data.requests || [];
                }
            } catch (e) {}
        }

        // Fetch by publicBody (additive — merge results not already found by date)
        if (publicBody) {
            const orgParams = new URLSearchParams({ publicBody });
            try {
                const resp = await fetch(`${this.searchRequestsUrlValue}?${orgParams.toString()}`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                if (resp.ok) {
                    const data = await resp.json();
                    const existingIds = new Set(suggestedResults.map(r => r.id));
                    for (const req of (data.requests || [])) {
                        if (!existingIds.has(req.id)) {
                            suggestedResults.push(req);
                        }
                    }
                }
            } catch (e) {}
        }

        // Render suggested
        if (suggestedContainer) {
            if (suggestedResults.length > 0) {
                this.renderRequestList(suggestedContainer, suggestedResults, 'Solicitudes sugeridas', true);
            } else {
                suggestedContainer.innerHTML = '';
            }
        }

        // Fetch all requests
        if (allContainer) {
            try {
                const resp = await fetch(`${this.searchRequestsUrlValue}`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                if (resp.ok) {
                    const data = await resp.json();
                    this.renderRequestList(allContainer, data.requests, 'Todas las solicitudes', false);
                }
            } catch (e) {
                console.error('Error loading all requests:', e);
            }
        }
    }

    renderRequestList(container, requests, title, isSuggested) {
        if (!requests || requests.length === 0) {
            container.innerHTML = isSuggested ? '' : `
                <h3 class="text-sm font-semibold text-slate-500 uppercase tracking-wide mb-3">${title}</h3>
                <p class="text-sm text-slate-500">No se encontraron solicitudes</p>
            `;
            return;
        }

        const statusBadgeMap = {
            'sent': 'status-sent',
            'processing': 'status-processing',
            'granted': 'status-granted',
            'denied': 'status-denied',
            'delayed': 'status-delayed',
            'pending': 'status-pending'
        };

        container.innerHTML = `
            <h3 class="text-sm font-semibold text-slate-500 uppercase tracking-wide mb-4">${title}</h3>
            <div class="space-y-3">
            ${requests.map(req => `
                <div class="p-4 rounded-xl border border-slate-200 hover:border-primary-400 hover:bg-primary-50/50 cursor-pointer transition-all group"
                     data-action="click->file-uploader#selectOrphanRequest"
                     data-request-id="${req.id}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex-1 min-w-0">
                            <p class="font-medium text-sm text-slate-800 truncate group-hover:text-primary-700">${req.title}</p>
                            <div class="flex flex-wrap items-center gap-2 mt-1 text-xs text-slate-500">
                                <span class="flex items-center gap-1">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                                    ${req.publicBody.name}
                                </span>
                                <span>${req.sentAt}</span>
                                ${req.externalId ? `<span class="text-primary-600">#${req.externalId}</span>` : ''}
                            </div>
                        </div>
                        <span class="badge ${statusBadgeMap[req.status] || 'badge-secondary'} flex-shrink-0">${req.statusLabel}</span>
                    </div>
                </div>
            `).join('')}
            </div>
        `;

        if (window.lucide) {
            window.lucide.createIcons();
        }
    }

    async selectOrphanRequest(event) {
        const requestId = event.currentTarget.dataset.requestId;
        const doc = this._currentOrphanDoc;

        if (!doc || !requestId) return;

        // Disable the clicked card
        event.currentTarget.classList.add('opacity-50', 'pointer-events-none');
        event.currentTarget.innerHTML += '<div class="text-center mt-2"><span class="text-sm text-primary-600">Enlazando...</span></div>';

        try {
            const response = await fetch(`/documentos/${doc.id}/link`, {
                method: 'POST',
                body: JSON.stringify({ accessRequestId: requestId }),
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (response.ok) {
                const result = await response.json();
                this.closeOrphanModal();
                this.showToast(`Documento enlazado a "${result.accessRequest.title}"`, 'success');
                this.triggerDashboardRefresh();
            } else {
                const err = await response.json();
                this.showToast(err.error || 'Error al enlazar documento', 'danger');
            }
        } catch (error) {
            console.error('Error linking document:', error);
            this.showToast('Error al enlazar documento', 'danger');
        }
    }

    skipOrphanLink() {
        this.closeOrphanModal();
    }

    createRequestFromOrphan() {
        this.closeOrphanModal();
        window.location.href = '/solicitudes/nueva';
    }

    // Search within the orphan modal
    searchOrphanRequests(event) {
        clearTimeout(this._orphanSearchTimeout);
        this._orphanSearchTimeout = setTimeout(async () => {
            const query = event.target.value.trim();
            const allContainer = document.getElementById('allRequests');

            if (!allContainer) return;

            try {
                const params = new URLSearchParams();
                if (query) params.set('q', query);

                const resp = await fetch(`${this.searchRequestsUrlValue}?${params.toString()}`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                if (resp.ok) {
                    const data = await resp.json();
                    this.renderRequestList(allContainer, data.requests, query ? 'Resultados de búsqueda' : 'Todas las solicitudes', false);
                }
            } catch (e) {
                console.error('Error searching requests:', e);
            }
        }, 300);
    }
}
