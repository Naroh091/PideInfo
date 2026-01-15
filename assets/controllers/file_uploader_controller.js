import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'preview', 'status', 'modal', 'dropzone', 'keywordMatchModal', 'keywordMatchList'];
    static values = {
        url: String,
        processUrl: { type: String, default: '/documentos/procesar' },
        keywordMatchUrl: { type: String, default: '/documentos/keyword-matched' },
        statusUrl: { type: String, default: '/documentos/status' },
        accessRequestId: { type: String, default: '' }
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
        this.showStatus(`Subiendo "${file.name}"...`, 'info');

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
                this.showStatus(`"${file.name}" subido correctamente`, 'success');
                this.addFileToPreview(result.document);
                this.uploadedDocuments.push(result.document);

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
                <div class="alert ${alertClass} animate-fade-in" role="alert">
                    <i data-lucide="${icon}" class="w-5 h-5 flex-shrink-0 ${type === 'info' ? 'animate-spin' : ''}"></i>
                    <span>${message}</span>
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

                // Show notification if documents were processed
                if (anyProcessed) {
                    this.showProcessingCompleteNotification(processedDocs);

                    // Trigger refresh of LiveComponents on the page
                    this.triggerDashboardRefresh();
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
}
