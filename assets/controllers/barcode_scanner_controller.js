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

        this._boundStopScanning = () => this.stopScanning();
        window.addEventListener('barcode-scan:close', this._boundStopScanning);

        // Set up native scanner callbacks for iOS/Android apps
        window.onNativeScanResult = (code) => this.handleNativeScanResult(code);
        window.onNativeScanCancelled = () => this.handleNativeScanCancelled();
    }

    disconnect() {
        this.stopScanning();
        window.removeEventListener('barcode-scan:close', this._boundStopScanning);

        // Clean up native scanner callbacks
        delete window.onNativeScanResult;
        delete window.onNativeScanCancelled;
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
        // Check if we're in a native app - use native scanner instead
        if (window.isNativeApp) {
            this.openNativeScanner();
            return;
        }

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            console.error('Camera API not available');
            alert('Camera is not available in this browser. Please try reloading the page.');
            return;
        }

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

        navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } } })
            .then(stream => {
                this.videoTarget.srcObject = stream;
                this.videoTarget.play().catch(err => console.error('Video play failed:', err));
                this.scanning = true;

                this.videoTarget.addEventListener('loadedmetadata', () => {
                    this.overlayTarget.width = this.videoTarget.videoWidth;
                    this.overlayTarget.height = this.videoTarget.videoHeight;
                }, { once: true });

                this.setupZoom(stream);
                this.scanLoop();
            })
            .catch(error => {
                console.error('Error accessing the camera:', error);
                this.stopScanning();
            });
    }

    setupZoom(stream) {
        try {
            const videoTrack = stream.getVideoTracks()[0];
            if (!videoTrack || typeof videoTrack.getCapabilities !== 'function') {
                if (this.hasZoomContainerTarget) {
                    this.zoomContainerTarget.classList.add('d-none');
                }
                return;
            }

            const capabilities = videoTrack.getCapabilities();
            if (capabilities.zoom) {
                this.zoomSupported = true;
                this.videoTrack = videoTrack;
                this.zoomCapabilities = capabilities.zoom;
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
                this.zoomContainerTarget.classList.add('d-none');
            }
        } catch (err) {
            console.error('Zoom setup failed:', err);
            if (this.hasZoomContainerTarget) {
                this.zoomContainerTarget.classList.add('d-none');
            }
        }
    }

    async _ensureBarcodeDetector() {
        if (this._polyfillLoaded) return;

        // Load zbar-wasm first, then the polyfill that depends on it
        await this._loadScript('https://cdn.jsdelivr.net/npm/@undecaf/zbar-wasm@0.9.15/dist/index.js');
        await this._loadScript('https://cdn.jsdelivr.net/npm/@undecaf/barcode-detector-polyfill@0.9.21/dist/index.js');

        const polyfillAvailable = typeof barcodeDetectorPolyfill !== 'undefined' && barcodeDetectorPolyfill.BarcodeDetectorPolyfill;

        try {
            if (window.BarcodeDetector && typeof window.BarcodeDetector.getSupportedFormats === 'function') {
                const formats = await window.BarcodeDetector.getSupportedFormats();
                if (formats.indexOf('ean_13') === -1 && polyfillAvailable) {
                    window.BarcodeDetector = barcodeDetectorPolyfill.BarcodeDetectorPolyfill;
                }
            } else if (polyfillAvailable) {
                window.BarcodeDetector = barcodeDetectorPolyfill.BarcodeDetectorPolyfill;
            }
        } catch (e) {
            if (polyfillAvailable) {
                window.BarcodeDetector = barcodeDetectorPolyfill.BarcodeDetectorPolyfill;
            }
        }

        this._polyfillLoaded = true;
    }

    _loadScript(src) {
        return new Promise((resolve, reject) => {
            // Skip if already loaded
            if (document.querySelector(`script[src="${src}"]`)) {
                resolve();
                return;
            }
            const script = document.createElement('script');
            script.src = src;
            script.onload = resolve;
            script.onerror = reject;
            document.head.appendChild(script);
        });
    }

    async scanLoop() {
        await this._ensureBarcodeDetector();

        if (typeof BarcodeDetector === 'undefined') {
            console.error('BarcodeDetector is not available');
            return;
        }

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

        // Stop all tracks and release the stream
        const stream = this.videoTarget.srcObject;
        if (stream) {
            stream.getTracks().forEach(track => track.stop());
        }

        // Fully release video element resources
        this.videoTarget.pause();
        this.videoTarget.srcObject = null;
        this.videoTarget.load(); // Forces browser to release video memory

        // Clear canvas and reset dimensions to release memory
        const ctx = this.overlayTarget.getContext('2d');
        ctx.clearRect(0, 0, this.overlayTarget.width, this.overlayTarget.height);
        this.overlayTarget.width = 0;
        this.overlayTarget.height = 0;

        // Clear references to allow garbage collection
        this.videoTrack = null;
        this.zoomCapabilities = null;
        this.scanBuffer = [];
        this.zoomSupported = false;
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

    // --- Native App Scanner Bridge Methods ---

    /**
     * Opens the native barcode scanner for iOS or Android apps.
     * The native app will call onNativeScanResult() or onNativeScanCancelled() callbacks.
     */
    openNativeScanner() {
        this.toggleButtonTarget.classList.add('active');

        if (this.inputTarget.value !== '') {
            this.inputTarget.value = '';
        }

        if (this.hasResultsTarget) {
            this.resultsTarget.classList.add('d-none');
        }

        if (window.nativePlatform === 'ios') {
            // iOS: Use WKScriptMessageHandler bridge
            if (window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.scanner) {
                window.webkit.messageHandlers.scanner.postMessage({ action: 'open' });
            } else {
                console.error('iOS scanner bridge not available');
                // Fallback to web scanner if bridge not available
                this.initWebScanner();
            }
        } else if (window.nativePlatform === 'android') {
            // Android: Use JavascriptInterface bridge
            if (window.AndroidScanner && typeof window.AndroidScanner.openScanner === 'function') {
                window.AndroidScanner.openScanner();
            } else {
                console.error('Android scanner bridge not available');
                // Fallback to web scanner if bridge not available
                this.initWebScanner();
            }
        }
    }

    /**
     * Fallback method to use web scanner when native bridge is not available.
     */
    initWebScanner() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            console.error('Camera API not available');
            alert('Camera is not available in this browser. Please try reloading the page.');
            return;
        }

        this.wrapperTarget.classList.remove('d-none');
        this.scanBuffer = [];
        this.lastPushTime = 0;

        navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } } })
            .then(stream => {
                this.videoTarget.srcObject = stream;
                this.videoTarget.play().catch(err => console.error('Video play failed:', err));
                this.scanning = true;

                this.videoTarget.addEventListener('loadedmetadata', () => {
                    this.overlayTarget.width = this.videoTarget.videoWidth;
                    this.overlayTarget.height = this.videoTarget.videoHeight;
                }, { once: true });

                this.setupZoom(stream);
                this.scanLoop();
            })
            .catch(error => {
                console.error('Error accessing the camera:', error);
                this.stopScanning();
            });
    }

    /**
     * Callback for successful native barcode scan.
     * Called by native iOS/Android code when a barcode is detected.
     */
    handleNativeScanResult(code) {
        this.toggleButtonTarget.classList.remove('active');

        // Set the input value
        this.inputTarget.value = code;
        this.inputTarget.dispatchEvent(new Event('change', { bubbles: true }));

        // Dispatch custom event for other controllers to listen (e.g., time_form_autocomplete_controller)
        this.element.dispatchEvent(new CustomEvent('barcode-scanner:scanned', {
            detail: { code: code },
            bubbles: true
        }));

        if (this.hasResultsTarget) {
            this.resultsTarget.classList.remove('d-none');
        }
    }

    /**
     * Callback for cancelled native barcode scan.
     * Called by native iOS/Android code when user cancels the scanner.
     */
    handleNativeScanCancelled() {
        this.toggleButtonTarget.classList.remove('active');

        // Dispatch close event for other controllers
        window.dispatchEvent(new CustomEvent('barcode-scan:close'));
    }
}
