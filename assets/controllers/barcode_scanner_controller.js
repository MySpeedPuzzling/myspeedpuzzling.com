import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ["video", "input", "wrapper", "toggleButton"]

    connect() {
        this.scanning = false;
        this.lastScan = '';

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

        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
            .then(stream => {
                this.videoTarget.srcObject = stream;
                this.videoTarget.play();
                this.scanning = true;
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

        const scanFrame = async () => {
            if (!this.scanning) {
                return;
            }

            try {
                const barcodes = await barcodeDetector.detect(this.videoTarget);
                if (barcodes.length > 0) {
                    // TODO: Consider barcodes[0].quality > x

                    const code = barcodes[0].rawValue;

                    this.lastScan = code;
                    this.inputTarget.value = code;
                    const changeEvent = new Event('change', { bubbles: true });
                    this.inputTarget.dispatchEvent(changeEvent);

                    return;
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
    }
}
