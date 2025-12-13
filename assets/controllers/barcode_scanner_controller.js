import { Controller } from '@hotwired/stimulus';
import Barcoder from 'barcoder';

export default class extends Controller {
    static targets = [
        "video",
        "input",
        "wrapper",
        "toggleButton",
        "overlay",
        "results",
        "zoomContainer",
        "zoomValue",
        "zoomIn",
        "zoomOut",
    ];

    connect() {
        if (!this.hasToggleButtonTarget) {
            return;
        }

        this.scanning = false;
        this.scanBuffer = [];
        this.lastPushTime = 0;
        this.currentZoom = 1;
        this.zoomSupported = false;
        this.videoTrack = null;
        this.zoomCapabilities = null;

        window.addEventListener('barcode-scan:close', () => this.stopScanning());
    }

    toggle(event) {
        event.preventDefault();

        if (this.scanning) {
            this.stopScanning()
        } else {
            this.initCamera();
        }
    }

    initCamera() {
        this.wrapperTarget.classList.remove('d-none');
        this.toggleButtonTarget.classList.add('active');
        this.scanBuffer = [];
        this.lastPushTime = 0;

        if (this.inputTarget.value !== '') {
            this.inputTarget.value = '';
        }

        if (this.hasResultsTarget) {
            this.resultsTarget.classList.add('d-none');
        }

        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
            .then(stream => {
                this.videoTarget.srcObject = stream;
                this.videoTarget.play();
                this.scanning = true;

                this.videoTarget.addEventListener('loadedmetadata', () => {
                    this.overlayTarget.width = this.videoTarget.videoWidth;
                    this.overlayTarget.height = this.videoTarget.videoHeight;
                }, { once: true });

                // --- Zoom Setup ---
                const videoTrack = stream.getVideoTracks()[0];
                const capabilities = videoTrack.getCapabilities();
                if (capabilities.zoom) {
                    this.zoomSupported = true;
                    this.videoTrack = videoTrack;
                    this.zoomCapabilities = capabilities.zoom;
                    // Clamp default zoom (2) between min and max.
                    const defaultZoom = Math.min(capabilities.zoom.max, Math.max(capabilities.zoom.min, 2));
                    videoTrack.applyConstraints({
                        advanced: [{ zoom: defaultZoom }]
                    })
                        .then(() => {
                            this.currentZoom = defaultZoom;
                            if (this.hasZoomContainerTarget) {
                                this.zoomContainerTarget.classList.remove('d-none');
                                this.updateZoomUI();
                            }
                        })
                        .catch(err => console.error('Failed to apply zoom constraint:', err));
                } else if (this.hasZoomContainerTarget) {
                    // Hide zoom UI if zoom is not supported.
                    this.zoomContainerTarget.classList.add('d-none');
                }
                // --- End Zoom Setup ---

                this.scanLoop();
            })
            .catch(error => {
                console.error('Error accessing the camera:', error);
            });
    }

    async scanLoop() {
        const barcodeDetector = new BarcodeDetector({ formats: ['ean_8', 'ean_13'] });
        const ctx = this.overlayTarget.getContext('2d');

        const scanFrame = async () => {
            if (!this.scanning) {
                return;
            }

            // Clear the overlay canvas before drawing new results.
            ctx.clearRect(0, 0, this.overlayTarget.width, this.overlayTarget.height);

            try {
                const barcodes = await barcodeDetector.detect(this.videoTarget);
                if (barcodes.length > 0) {

                    let barcode = barcodes[0];
                    const code = barcode.rawValue;

                    // Draw bounding polygon if corner points are provided.
                    if (barcode.cornerPoints && barcode.cornerPoints.length > 0) {
                        ctx.beginPath();
                        ctx.moveTo(barcode.cornerPoints[0].x, barcode.cornerPoints[0].y);
                        for (let i = 1; i < barcode.cornerPoints.length; i++) {
                            ctx.lineTo(barcode.cornerPoints[i].x, barcode.cornerPoints[i].y);
                        }
                        ctx.closePath();
                        ctx.lineWidth = 3;
                        ctx.strokeStyle = '#fe4042';  // green color for the bounding box
                        ctx.stroke();
                    }

                    if (Barcoder.validate(code) && (barcode.quality === undefined || barcode.quality > 8)) {
                        const now = Date.now();
                        // Only push if at least X...ms have elapsed since the last push.
                        if (!this.lastPushTime || now - this.lastPushTime >= 2) {
                            this.lastPushTime = now;
                            this.scanBuffer.push(code);
                            // Count how many times this code appears in the buffer.
                            const count = this.scanBuffer.filter(c => c === code).length;
                            if (count >= 10) {
                                this.inputTarget.value = code;
                                this.inputTarget.dispatchEvent(new Event('change', {bubbles: true}));

                                // Dispatch custom event for other controllers to listen
                                this.element.dispatchEvent(new CustomEvent('barcode-scanner:scanned', {
                                    detail: { code: code },
                                    bubbles: true
                                }));

                                // Remove all entries for this code, leaving other codes in the buffer.
                                this.scanBuffer = [];
                                this.stopScanning();
                            }
                        }
                    }
                }
            } catch (error) {
                console.error('Barcode detection error:', error);
            }

            requestAnimationFrame(scanFrame);
        };

        scanFrame();
    }

    stopScanning() {
        if (this.hasResultsTarget) {
            this.resultsTarget.classList.remove('d-none');
        }

        this.scanning = false;
        this.wrapperTarget.classList.add('d-none');
        this.toggleButtonTarget.classList.remove('active');

        const stream = this.videoTarget.srcObject;
        if (stream) {
            stream.getTracks().forEach(track => track.stop());
        }

        this.videoTarget.srcObject = null;

        const ctx = this.overlayTarget.getContext('2d');
        ctx.clearRect(0, 0, this.overlayTarget.width, this.overlayTarget.height);
    }

    zoomIn() {
        if (!this.videoTrack || !this.zoomCapabilities) return;
        // Increase zoom by 0.5, but do not exceed max.
        const newZoom = Math.min(this.zoomCapabilities.max, this.currentZoom + 0.5);
        if (newZoom === this.currentZoom) return;
        this.videoTrack.applyConstraints({ advanced: [{ zoom: newZoom }] })
            .then(() => {
                this.currentZoom = newZoom;
                this.updateZoomUI();
            })
            .catch(err => console.error('Failed to apply zoom constraint:', err));
    }

    zoomOut() {
        if (!this.videoTrack || !this.zoomCapabilities) return;
        // Decrease zoom by 0.5, but do not go below min.
        const newZoom = Math.max(this.zoomCapabilities.min, this.currentZoom - 0.5);
        if (newZoom === this.currentZoom) return;
        this.videoTrack.applyConstraints({ advanced: [{ zoom: newZoom }] })
            .then(() => {
                this.currentZoom = newZoom;
                this.updateZoomUI();
            })
            .catch(err => console.error('Failed to apply zoom constraint:', err));
    }

    updateZoomUI() {
        if (!this.hasZoomValueTarget || !this.hasZoomInTarget || !this.hasZoomOutTarget || !this.zoomCapabilities) return;
        // Update the display for the current zoom level.
        this.zoomValueTarget.textContent = `Zoom: ${this.currentZoom.toFixed(1)}x`;

        // Disable zoomIn if at maximum, disable zoomOut if at minimum.
        if (this.currentZoom >= this.zoomCapabilities.max) {
            this.zoomInTarget.classList.add('disabled');
        } else {
            this.zoomInTarget.classList.remove('disabled');
        }
        if (this.currentZoom <= this.zoomCapabilities.min) {
            this.zoomOutTarget.classList.add('disabled');
        } else {
            this.zoomOutTarget.classList.remove('disabled');
        }
    }
}
