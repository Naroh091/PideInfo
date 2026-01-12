import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'preview', 'status', 'modal', 'dropzone'];
    static values = {
        url: String,
        processUrl: { type: String, default: '/documentos/procesar' },
        accessRequestId: { type: String, default: '' }
    };

    connect() {
        this.uploadedDocuments = [];
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
            item.className = 'flex items-center justify-between p-3 bg-slate-50 rounded-xl';
            item.innerHTML = `
                <div class="flex items-center gap-3 min-w-0">
                    <div class="w-10 h-10 rounded-lg bg-primary-100 flex items-center justify-center flex-shrink-0">
                        <i data-lucide="file-text" class="w-5 h-5 text-primary-600"></i>
                    </div>
                    <div class="min-w-0">
                        <p class="font-medium text-slate-800 truncate">${doc.name}</p>
                        <p class="text-sm text-slate-500">${doc.size}</p>
                    </div>
                </div>
                <span class="badge badge-warning flex-shrink-0">Pendiente</span>
            `;
            this.previewTarget.appendChild(item);

            // Re-initialize Lucide icons for the new content
            if (window.lucide) {
                window.lucide.createIcons();
            }
        }
    }
}
