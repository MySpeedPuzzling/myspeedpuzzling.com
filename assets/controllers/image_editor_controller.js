import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

export default class extends Controller {
    static values = {
        title: { type: String, default: 'Edit Image' },
        rotateLeft: { type: String, default: 'Rotate Left' },
        rotateRight: { type: String, default: 'Rotate Right' },
        cancel: { type: String, default: 'Cancel' },
        apply: { type: String, default: 'Apply' },
        edit: { type: String, default: 'Edit' },
    };

    CropperClass = null;
    cropper = null;
    originalFile = null;
    currentFileInput = null;
    modalElement = null;
    modalInstance = null;
    imageElement = null;

    connect() {
        this.createModal();
    }

    disconnect() {
        this.destroyCropper();
        if (this.modalElement) {
            this.modalElement.remove();
        }
    }

    async openEditor(event) {
        event.preventDefault();

        const fileDropArea = event.currentTarget.closest('.file-drop-area');
        const fileInput = fileDropArea.querySelector('.file-drop-input');

        if (!fileInput.files || !fileInput.files[0]) {
            return;
        }

        if (!this.CropperClass) {
            const [{ default: Cropper }] = await Promise.all([
                import('cropperjs'),
                import('cropperjs/dist/cropper.min.css'),
            ]);
            this.CropperClass = Cropper;
        }

        this.originalFile = fileInput.files[0];
        this.currentFileInput = fileInput;

        const reader = new FileReader();
        reader.onload = (e) => {
            this.imageElement.src = e.target.result;
            this.showModal();
        };
        reader.readAsDataURL(this.originalFile);
    }

    createModal() {
        if (document.getElementById('imageEditorModal')) {
            this.modalElement = document.getElementById('imageEditorModal');
            this.imageElement = this.modalElement.querySelector('.image-editor-image');
            this.bindModalEvents();
            return;
        }

        const modalHtml = `
            <div class="modal fade" id="imageEditorModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header border-0">
                            <h5 class="modal-title">${this.titleValue}</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body p-0">
                            <div class="image-editor-container">
                                <img class="image-editor-image" alt="">
                            </div>
                        </div>
                        <div class="modal-footer border-0 justify-content-between">
                            <div class="btn-group">
                                <button type="button" class="btn btn-outline-secondary js-rotate-left" title="${this.rotateLeftValue}">
                                    <i class="bi bi-arrow-counterclockwise"></i>
                                </button>
                                <button type="button" class="btn btn-outline-secondary js-rotate-right" title="${this.rotateRightValue}">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </button>
                            </div>
                            <div>
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                                    ${this.cancelValue}
                                </button>
                                <button type="button" class="btn btn-primary js-apply">
                                    ${this.applyValue}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHtml);
        this.modalElement = document.getElementById('imageEditorModal');
        this.imageElement = this.modalElement.querySelector('.image-editor-image');

        this.bindModalEvents();
    }

    bindModalEvents() {
        // Init cropper after modal is fully shown (fixes viewport size issue)
        this.modalElement.addEventListener('shown.bs.modal', () => {
            this.initCropper();
        });

        // Destroy cropper when modal is hidden
        this.modalElement.addEventListener('hidden.bs.modal', () => {
            this.destroyCropper();
        });

        // Button event listeners (manual binding since modal is outside controller scope)
        this.modalElement.querySelector('.js-rotate-left').addEventListener('click', () => {
            this.rotateLeft();
        });

        this.modalElement.querySelector('.js-rotate-right').addEventListener('click', () => {
            this.rotateRight();
        });

        this.modalElement.querySelector('.js-apply').addEventListener('click', () => {
            this.apply();
        });
    }

    initCropper() {
        this.destroyCropper();

        this.cropper = new this.CropperClass(this.imageElement, {
            viewMode: 0,
            dragMode: 'crop',
            autoCropArea: 1,
            responsive: true,
            restore: false,
            guides: true,
            center: true,
            highlight: true,
            cropBoxMovable: true,
            cropBoxResizable: true,
            toggleDragModeOnDblclick: false,
            minContainerWidth: 200,
            minContainerHeight: 200,
        });
    }

    destroyCropper() {
        if (this.cropper) {
            this.cropper.destroy();
            this.cropper = null;
        }
    }

    rotateLeft() {
        this.rotate(-90);
    }

    rotateRight() {
        this.rotate(90);
    }

    rotate(degrees) {
        if (!this.cropper) {
            return;
        }

        this.cropper.rotate(degrees);
        setTimeout(() => this.centerImageAndFitCropBox(), 50);
    }

    centerImageAndFitCropBox() {
        const containerData = this.cropper.getContainerData();
        const canvasData = this.cropper.getCanvasData();

        const left = (containerData.width - canvasData.width) / 2;
        const top = (containerData.height - canvasData.height) / 2;

        this.cropper.setCanvasData({ left, top });

        const newCanvasData = this.cropper.getCanvasData();
        this.cropper.setCropBoxData({
            left: newCanvasData.left,
            top: newCanvasData.top,
            width: newCanvasData.width,
            height: newCanvasData.height
        });
    }

    apply() {
        if (!this.cropper) {
            return;
        }

        const canvas = this.cropper.getCroppedCanvas({
            maxWidth: 2048,
            maxHeight: 2048,
            imageSmoothingEnabled: true,
            imageSmoothingQuality: 'high',
        });

        canvas.toBlob((blob) => {
            const fileName = this.originalFile.name.replace(/\.[^.]+$/, '.jpg');
            const file = new File([blob], fileName, {
                type: 'image/jpeg',
                lastModified: Date.now()
            });

            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(file);
            this.currentFileInput.files = dataTransfer.files;

            this.currentFileInput.dispatchEvent(new Event('change', { bubbles: true }));

            this.hideModal();
        }, 'image/jpeg', 0.92);
    }

    showModal() {
        this.modalInstance = Modal.getOrCreateInstance(this.modalElement);
        this.modalInstance.show();
    }

    hideModal() {
        if (this.modalInstance) {
            this.modalInstance.hide();
        }
    }
}
