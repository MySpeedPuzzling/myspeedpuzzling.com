import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ["video", "input", "wrapper", "toggleButton", "overlay"]

    connect() {
        this.scanning = false;
        this.scanBuffer = [];
        this.lastPushTime = 0;

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
            this.inputTarget.dispatchEvent(new Event('change', { bubbles: true }));
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

                this.scanLoop();
            })
            .catch(error => {
                console.error('Error accessing the camera:', error);
            });
    }

    async scanLoop() {
        // Use the global BarcodeDetector that is either native or provided by the polyfill.
        const formats = await BarcodeDetector.getSupportedFormats()
        const barcodeDetector = new BarcodeDetector({ formats });
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

                    console.log(barcode);

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

                    // Check for quality: if quality is provided, accept only if >20.
                    if (barcode.quality === undefined || barcode.quality > 20) {
                        const now = Date.now();
                        // Only push if at least 5ms have elapsed since the last push.
                        if (!this.lastPushTime || now - this.lastPushTime >= 5) {
                            this.lastPushTime = now;
                            this.scanBuffer.push(code);

                            // Count how many times this code appears in the buffer.
                            const count = this.scanBuffer.filter(c => c === code).length;
                            if (count >= 10) {
                                this.inputTarget.value = code;
                                this.inputTarget.dispatchEvent(new Event('change', { bubbles: true }));
                                // Remove all entries for this code, leaving other codes in the buffer.
                                this.scanBuffer = [];
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
}
