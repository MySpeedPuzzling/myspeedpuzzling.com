import { Controller } from '@hotwired/stimulus';
import { browserDiagnostics, deviceModel, isNativeApp, nativePlatform, os } from '../platform.js';

// docs/features/barcode-scanner/README.md (§5.2 camera, §5.3 frames and acceptance)
const DEFAULT_ZOOM = 1.5;
const ZOOM_STEP = 0.5;
// Asked in landscape numbers: Chrome on Android otherwise opens 640x480 (480 px across held upright), where zbar
// only reads closer than the camera can focus. resizeMode none = a format the camera has, not a scaled one.
const VIDEO_SIZE = { width: { ideal: 1920 }, height: { ideal: 1080 }, resizeMode: { ideal: 'none' } };
// Manual focus here, then back to continuous: a mode change restarts Chrome's autofocus, 'single-shot' does nothing
const FOCUS_KICK_DISTANCE_M = 0.25;
const FOCUS_KICK_HOLD_MS = 250;
const FOCUS_KICK_FIRST_MS = 2500;
const FOCUS_KICK_EVERY_MS = 6000;
// A read is accepted only while no other code was seen this recently
const COMPETING_CODE_MS = 2000;

const STORAGE_CAMERA = 'msp-scan-camera';
const STORAGE_ZOOM = 'msp-scan-zoom';
const STORAGE_SOUND = 'msp-scan-sound';

export default class extends Controller {
    static targets = [
        "video",
        "input",
        "wrapper",
        "toggleButton",
        "overlay",
        "results",
        "zoomContainer",
        "zoomControls",
        "zoomValue",
        "zoomIn",
        "zoomOut",
        "torchButton",
        "hint",
        "flash",
        "soundButton",
    ];

    // Continuous mode (multiscan): keep the camera running after a read, ignore the
    // same code for `cooldownMs`, give read/outcome feedback (vibration, beep, flash)
    // and let the host pause/resume detection while it handles a result.
    static values = {
        continuous: { type: Boolean, default: false },
        autostart: { type: Boolean, default: false },
        cooldownMs: { type: Number, default: 2500 },
        // "The camera is not available in this browser…" (barcode_scanner.no_camera) - shown in the page's toast
        noCamera: { type: String, default: '' },
    };

    connect() {
        if (!this.hasToggleButtonTarget) {
            return;
        }

        this.scanning = false;
        this.paused = false;
        this.cameraSession = 0;
        this._resetCameraState();
        this.acceptedAt = new Map();
        this.nativeAwaitingResume = false;
        this.audioContext = null;
        this.soundEnabled = this._readSoundPreference();
        this._updateSoundButton();

        this._boundStopScanning = () => this.stopScanning();
        window.addEventListener('barcode-scan:close', this._boundStopScanning);

        this._boundPause = () => this.pause();
        this._boundResume = () => this.resume();
        window.addEventListener('barcode-scan:pause', this._boundPause);
        window.addEventListener('barcode-scan:resume', this._boundResume);

        // Tap the picture to refocus (the hint says so); the pill's buttons do their own thing
        this._boundTap = (event) => {
            if (this.scanning && !event.target.closest('button')) {
                this.kickFocus('tap');
            }
        };
        this.wrapperTarget.addEventListener('click', this._boundTap);

        // Native scanner callbacks for iOS/Android apps - claimed when this scanner opens the native one, so another
        // scanner (re)connecting meanwhile, e.g. the global search re-rendering, cannot take the result
        this._nativeScanResult = (code) => this.handleNativeScanResult(code);
        this._nativeScanCancelled = () => this.handleNativeScanCancelled();

        if (this.autostartValue) {
            requestAnimationFrame(() => this.initCamera());
        }
    }

    disconnect() {
        // connect() set nothing up without the toggle button (and stopScanning() needs it)
        if (!this.hasToggleButtonTarget) {
            return;
        }

        this.stopScanning();
        window.removeEventListener('barcode-scan:close', this._boundStopScanning);
        window.removeEventListener('barcode-scan:pause', this._boundPause);
        window.removeEventListener('barcode-scan:resume', this._boundResume);
        this.wrapperTarget.removeEventListener('click', this._boundTap);

        // Clean up native scanner callbacks - only our own, another scanner on the page may hold them
        if (window.onNativeScanResult === this._nativeScanResult) {
            delete window.onNativeScanResult;
        }
        if (window.onNativeScanCancelled === this._nativeScanCancelled) {
            delete window.onNativeScanCancelled;
        }
    }

    // No camera API: an in-page notice (decision 96) - never a native alert, iOS Safari refuses those after a Back to
    // a pushState entry (GEST-13)
    _noCameraNotice() {
        if (this.noCameraValue === '') {
            return;
        }

        document.dispatchEvent(new CustomEvent('toast:show', { detail: { message: this.noCameraValue, type: 'error' } }));
    }

    // A page can hold several scanners (global search, a form in a modal): the native result goes to the one opened last
    _claimNativeCallbacks() {
        window.onNativeScanResult = this._nativeScanResult;
        window.onNativeScanCancelled = this._nativeScanCancelled;
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
        if (isNativeApp()) {
            this.openNativeScanner();
            return;
        }

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            console.error('Camera API not available');
            this._noCameraNotice();
            return;
        }

        this.toggleButtonTarget.classList.add('active');

        if (this.inputTarget.value !== '') {
            this.inputTarget.value = '';
        }

        if (this.hasResultsTarget) {
            this.resultsTarget.classList.add('d-none');
        }

        this._startCamera();
    }

    // --- Camera ---

    async _startCamera() {
        // Read on every start from the page's own URL, never remembered: a tab that once had a test link must not
        // keep the panel or the forced decoder on other pages (README §5.6)
        this.debug = this._urlParam('scan-debug') === '1';
        // ?scanner=zbar / ?scanner=native forces a decoder (support and testing, README §5.5)
        const forced = this._urlParam('scanner');
        this.forcedEngine = forced === 'zbar' || forced === 'native' ? forced : null;

        this.wrapperTarget.classList.remove('d-none');
        this.scanBuffer = [];
        const session = ++this.cameraSession;

        let opened;
        try {
            opened = await this._openStream();
        } catch (error) {
            console.error('Error accessing the camera:', error);
            if (session === this.cameraSession) {
                this.stopScanning();
            }
            return;
        }

        // Stopped (or started again) while the camera was opening
        if (session !== this.cameraSession) {
            opened.stream.getTracks().forEach(track => track.stop());
            return;
        }

        this.cameraPick = opened.pick;
        this.videoTrack = opened.stream.getVideoTracks()[0] || null;
        this.trackCapabilities = this._capabilities(this.videoTrack);
        this.videoTarget.srcObject = opened.stream;
        this.videoTarget.play().catch(err => console.error('Video play failed:', err));
        this.scanning = true;
        this.startedAt = Date.now();
        if (this.hasHintTarget) {
            this.hintTarget.classList.remove('d-none');
        }
        this.nextFocusKickAt = this.startedAt + FOCUS_KICK_FIRST_MS;

        this.videoTarget.addEventListener('loadedmetadata', () => this._sizeOverlay(), { once: true });

        this._setupTrack();
        this._startDebug();
        this.scanLoop();
    }

    /**
     * @returns {Promise<{stream: MediaStream, pick: string}>}
     */
    async _openStream() {
        const media = navigator.mediaDevices;
        const remembered = this._readStorage(window.localStorage, STORAGE_CAMERA);

        if (remembered && remembered !== 'facing') {
            try {
                return { stream: await media.getUserMedia(this._constraints({ deviceId: { exact: remembered } })), pick: 'remembered main lens' };
            } catch (e) {
                // Gone (cleared site data rotates the ids, another browser profile): pick again
                this._writeStorage(window.localStorage, STORAGE_CAMERA, null);
            }
        }

        const stream = await media.getUserMedia(this._constraints({ facingMode: { ideal: 'environment' } }));

        // Chrome on Android opens the highest-index back camera for facingMode - on phones with several back lenses
        // often the ultra-wide without autofocus. iPhones are fine: Safari picks the virtual camera that switches lenses.
        if (remembered === 'facing' || os() !== 'android') {
            return { stream, pick: 'facingMode' };
        }

        const main = await this._mainBackCamera(stream);
        if (main === null) {
            this._writeStorage(window.localStorage, STORAGE_CAMERA, 'facing');
            return { stream, pick: 'facingMode' };
        }

        // Several phones cannot hold two cameras open
        stream.getTracks().forEach(track => track.stop());

        try {
            const mainStream = await media.getUserMedia(this._constraints({ deviceId: { exact: main.deviceId } }));
            const focusModes = this._capabilities(mainStream.getVideoTracks()[0]).focusMode;

            if (!Array.isArray(focusModes) || focusModes.indexOf('continuous') !== -1) {
                this._writeStorage(window.localStorage, STORAGE_CAMERA, main.deviceId);
                return { stream: mainStream, pick: 'main lens' };
            }

            mainStream.getTracks().forEach(track => track.stop());
        } catch (e) {
            console.error('The main back camera could not be opened:', e);
        }

        this._writeStorage(window.localStorage, STORAGE_CAMERA, 'facing');

        return { stream: await media.getUserMedia(this._constraints({ facingMode: { ideal: 'environment' } })), pick: 'facingMode' };
    }

    _constraints(camera) {
        return { audio: false, video: Object.assign({}, camera, VIDEO_SIZE) };
    }

    /**
     * Android names its cameras "camera 0, facing back" (Chrome 139+) or "camera2 0, facing back"; 0 is the main one
     *
     * @returns {Promise<MediaDeviceInfo|null>} null = keep the camera we have
     */
    async _mainBackCamera(stream) {
        try {
            const current = stream.getVideoTracks()[0];
            const currentId = current && typeof current.getSettings === 'function' ? current.getSettings().deviceId : null;
            const devices = (await navigator.mediaDevices.enumerateDevices()).filter(d => d.kind === 'videoinput');
            this.cameraLabels = devices.map(d => d.label || '(no label)');
            const main = devices.find(d => /^camera2? 0\b.*facing back/i.test(d.label));

            return main && main.deviceId !== currentId ? main : null;
        } catch (e) {
            return null;
        }
    }

    _capabilities(track) {
        try {
            return track && typeof track.getCapabilities === 'function' ? (track.getCapabilities() || {}) : {};
        } catch (e) {
            return {};
        }
    }

    _settings() {
        try {
            return this.videoTrack && typeof this.videoTrack.getSettings === 'function' ? (this.videoTrack.getSettings() || {}) : {};
        } catch (e) {
            return {};
        }
    }

    async _setupTrack() {
        const track = this.videoTrack;
        const caps = this.trackCapabilities;

        this.zoomCapabilities = caps.zoom && caps.zoom.max > caps.zoom.min ? caps.zoom : null;
        this.torchSupported = caps.torch === true;

        if (this.zoomCapabilities) {
            const remembered = parseFloat(this._readStorage(window.localStorage, STORAGE_ZOOM));
            this.currentZoom = this._clampZoom(Number.isFinite(remembered) ? remembered : DEFAULT_ZOOM);
        }

        await this._applyCameraState();

        if (track === this.videoTrack) {
            this._updateHud();
        }
    }

    _clampZoom(zoom) {
        const caps = this.zoomCapabilities;

        return Math.min(caps.max, Math.max(caps.min, zoom));
    }

    /**
     * One call with everything the track should hold: applyConstraints replaces the whole set, and a zoom change
     * clears Chrome's focus point.
     *
     * @param {Object} focus override, e.g. the manual step of a focus kick
     */
    async _applyCameraState(focus = null) {
        const track = this.videoTrack;
        if (!track || typeof track.applyConstraints !== 'function') {
            return;
        }

        const caps = this.trackCapabilities;
        // Each set on its own: a set the camera cannot satisfy is skipped, the others still apply
        const advanced = [];
        if (this.zoomCapabilities) {
            advanced.push({ zoom: this.currentZoom });
        }
        if (focus !== null) {
            advanced.push(focus);
        } else if (Array.isArray(caps.focusMode) && caps.focusMode.indexOf('continuous') !== -1) {
            advanced.push({ focusMode: 'continuous' });
            advanced.push({ pointsOfInterest: [{ x: 0.5, y: 0.5 }] });
        }
        if (this.torchSupported) {
            advanced.push({ torch: this.torchOn });
        }
        if (advanced.length === 0) {
            return;
        }

        // Keep the size asked for when the camera opened; the device itself cannot change here
        const constraints = Object.assign({}, typeof track.getConstraints === 'function' ? track.getConstraints() : {});
        delete constraints.deviceId;
        delete constraints.facingMode;
        constraints.advanced = advanced;

        try {
            await track.applyConstraints(constraints);
        } catch (err) {
            console.error('Failed to apply camera constraints:', err);
        }
    }

    async kickFocus(reason = 'auto') {
        const track = this.videoTrack;
        const caps = this.trackCapabilities;
        if (!track || !Array.isArray(caps.focusMode) || caps.focusMode.indexOf('continuous') === -1) {
            return;
        }

        this.nextFocusKickAt = Date.now() + FOCUS_KICK_EVERY_MS;
        this.lastFocusKick = { at: Date.now(), reason };

        const range = caps.focusDistance;
        if (caps.focusMode.indexOf('manual') !== -1 && range && range.max > range.min) {
            const distance = Math.min(range.max, Math.max(range.min, FOCUS_KICK_DISTANCE_M));
            await this._applyCameraState({ focusMode: 'manual', focusDistance: distance });
            await new Promise(resolve => window.setTimeout(resolve, FOCUS_KICK_HOLD_MS));
        }

        if (track === this.videoTrack) {
            await this._applyCameraState();
        }
    }

    toggleTorch() {
        if (!this.torchSupported) return;

        this.torchOn = !this.torchOn;
        this._applyCameraState();
        this._updateHud();
    }

    zoomIn() {
        this._changeZoom(ZOOM_STEP);
    }

    zoomOut() {
        this._changeZoom(-ZOOM_STEP);
    }

    async _changeZoom(step) {
        if (!this.videoTrack || !this.zoomCapabilities) return;

        const zoom = this._clampZoom(Math.round((this.currentZoom + step) * 10) / 10);
        if (zoom === this.currentZoom) return;

        this.currentZoom = zoom;
        this._writeStorage(window.localStorage, STORAGE_ZOOM, String(zoom));
        this._updateHud();
        await this._applyCameraState();
    }

    _updateHud() {
        if (!this.hasZoomContainerTarget) return;

        const zoom = this.zoomCapabilities !== null;
        const torch = this.torchSupported && this.hasTorchButtonTarget;
        this.zoomContainerTarget.classList.toggle('d-none', !zoom && !torch);

        if (this.hasTorchButtonTarget) {
            this.torchButtonTarget.classList.toggle('d-none', !torch);
            this.torchButtonTarget.classList.toggle('active', this.torchOn);
            this.torchButtonTarget.setAttribute('aria-pressed', this.torchOn ? 'true' : 'false');
            const icon = this.torchButtonTarget.querySelector('i');
            if (icon) {
                icon.className = this.torchOn ? 'bi-lightbulb-fill' : 'bi-lightbulb';
            }
        }

        if (this.hasZoomControlsTarget) {
            this.zoomControlsTarget.classList.toggle('d-none', !zoom);
            this.zoomControlsTarget.classList.toggle('d-inline-flex', zoom);
        }

        if (!zoom || !this.hasZoomValueTarget) return;

        let number = this.currentZoom.toFixed(1);
        try {
            number = new Intl.NumberFormat(document.documentElement.lang || undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(this.currentZoom);
        } catch (e) { /* unknown locale */ }
        const label = this.zoomValueTarget.dataset.label || '%zoom%×';
        this.zoomValueTarget.textContent = label.replace('%zoom%', number);

        if (this.hasZoomInTarget) {
            this.zoomInTarget.classList.toggle('disabled', this.currentZoom >= this.zoomCapabilities.max);
        }
        if (this.hasZoomOutTarget) {
            this.zoomOutTarget.classList.toggle('disabled', this.currentZoom <= this.zoomCapabilities.min);
        }
    }

    _resetCameraState() {
        this.videoTrack = null;
        this.trackCapabilities = {};
        this.zoomCapabilities = null;
        this.currentZoom = 1;
        this.torchSupported = false;
        this.torchOn = false;
        this.cameraPick = null;
        this.scanBuffer = [];
        this.codeSeenAt = new Map();
        this.lastFocusKick = null;
        this.nextFocusKickAt = 0;
        this.stats = { frames: 0, decodeMs: [], seen: new Map(), accepted: null, region: null };
    }

    // --- Decoding ---

    async _detectorClass() {
        if (this._Detector !== undefined && this._detectorForced === this.forcedEngine) return this._Detector;
        this._detectorForced = this.forcedEngine;

        // The browser's own detector where it reads EAN-13 (Google's engine in Android Chrome and Samsung
        // Internet, Apple's in Chrome on macOS), our zbar everywhere else (iOS, Firefox, Chrome on Windows/Linux).
        // zbar on Android too (2026-10-04 to 10-06) cost about half of the Android scans: Chrome opens the
        // camera at 640x480, often from the wrong lens, and zbar cannot read that at a distance the camera
        // focuses on. Google's engine can, but it now and then returns another valid code (Ravensburger
        // 4005555011897 as 0045555011897), which `EanList` refuses when a code is stored.
        // The plan out of both: docs/features/barcode-scanner/README.md.
        // A forced native detector where the browser has none falls back to zbar (the debug panel says so).
        if (this.forcedEngine !== 'zbar' && await this._nativeReadsEan()) {
            this._Detector = window.BarcodeDetector;
            this.engine = 'native';
            return this._Detector;
        }

        try {
            // Load zbar-wasm first, then the polyfill that depends on it
            await this._loadScript('https://cdn.jsdelivr.net/npm/@undecaf/zbar-wasm@0.9.15/dist/index.js');
            await this._loadScript('https://cdn.jsdelivr.net/npm/@undecaf/barcode-detector-polyfill@0.9.21/dist/index.js');
        } catch (e) {
            console.error('Barcode decoder could not be loaded:', e);
        }

        if (typeof barcodeDetectorPolyfill !== 'undefined' && barcodeDetectorPolyfill.BarcodeDetectorPolyfill) {
            this._Detector = barcodeDetectorPolyfill.BarcodeDetectorPolyfill;
            this.engine = 'zbar';
        } else {
            this._Detector = null;
            this.engine = 'none';
        }

        return this._Detector;
    }

    async _nativeReadsEan() {
        if (!window.BarcodeDetector || typeof window.BarcodeDetector.getSupportedFormats !== 'function') {
            return false;
        }

        try {
            // An empty list where the browser has the API but no engine behind it
            const formats = await window.BarcodeDetector.getSupportedFormats();

            return formats.indexOf('ean_13') !== -1;
        } catch (e) {
            return false;
        }
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
        if (!this._Barcoder) {
            const mod = await import('barcoder');
            this._Barcoder = mod.default;
        }
        const Detector = await this._detectorClass();

        if (Detector === null) {
            console.error('BarcodeDetector is not available');
            return;
        }

        const barcodeDetector = new Detector({ formats: ['ean_8', 'ean_13'] });
        const ctx = this.overlayTarget.getContext('2d');
        // A stop followed by a quick restart must not leave the old loop running next to the new one
        const generation = this.scanGeneration = (this.scanGeneration || 0) + 1;

        const scanFrame = async () => {
            if (!this.scanning || generation !== this.scanGeneration) {
                return;
            }

            if (this.paused) {
                // Keep the stream alive, skip detection while the host handles a result
                this._onNextFrame(scanFrame);
                return;
            }

            const now = Date.now();
            if (now >= this.nextFocusKickAt) {
                this.kickFocus();
            }

            this._sizeOverlay();
            ctx.clearRect(0, 0, this.overlayTarget.width, this.overlayTarget.height);
            this._drawGuide(ctx);

            try {
                const frame = this._frame();
                if (frame !== null) {
                    const decodeStart = performance.now();
                    const barcodes = await barcodeDetector.detect(frame.source);
                    this._recordDecode(performance.now() - decodeStart, frame);

                    if (barcodes.length > 0 && this.scanning && generation === this.scanGeneration) {
                        this._handleBarcodes(barcodes, frame, ctx);
                    }
                }
            } catch (error) {
                console.error('Barcode detection error:', error);
            }

            this._onNextFrame(scanFrame);
        };

        scanFrame();
    }

    /**
     * The browser's detector gets the whole frame (Google asks for 720p-1080p and reads any rotation). zbar gets a
     * centred band of what the player sees, at full resolution - a whole 1080p frame would cost it ~6x today's
     * 640x480 - alternating with an upright band where the picture is taller than wide, so a box held sideways reads.
     *
     * @returns {{source: CanvasImageSource|ImageData, x: number, y: number, w: number, h: number, vertical: boolean}|null}
     */
    _frame() {
        const video = this.videoTarget;
        if (!video.videoWidth || !video.videoHeight) {
            return null;
        }

        if (this.engine !== 'zbar') {
            return { source: video, x: 0, y: 0, w: video.videoWidth, h: video.videoHeight, vertical: false };
        }

        const visible = this._visibleRect();
        const vertical = visible.h >= visible.w && this.stats.frames % 2 === 1;
        const band = vertical ? this._verticalBand(visible) : this._horizontalBand(visible);

        this._cropCanvases = this._cropCanvases || {};
        const key = vertical ? 'v' : 'h';
        let crop = this._cropCanvases[key];
        if (!crop) {
            const canvas = document.createElement('canvas');
            crop = this._cropCanvases[key] = { canvas, ctx: canvas.getContext('2d', { willReadFrequently: true }) };
        }
        if (crop.canvas.width !== band.w || crop.canvas.height !== band.h) {
            crop.canvas.width = band.w;
            crop.canvas.height = band.h;
        }

        crop.ctx.drawImage(video, band.x, band.y, band.w, band.h, 0, 0, band.w, band.h);

        return Object.assign({ source: crop.ctx.getImageData(0, 0, band.w, band.h), vertical }, band);
    }

    // The part of the video on screen: multiscan crops it with object-fit: cover
    _visibleRect() {
        const video = this.videoTarget;
        const vw = video.videoWidth;
        const vh = video.videoHeight;
        const ew = video.clientWidth;
        const eh = video.clientHeight;

        if (!ew || !eh || window.getComputedStyle(video).objectFit !== 'cover') {
            return { x: 0, y: 0, w: vw, h: vh };
        }

        const scale = Math.max(ew / vw, eh / vh);
        const w = Math.min(vw, ew / scale);
        const h = Math.min(vh, eh / scale);

        return { x: (vw - w) / 2, y: (vh - h) / 2, w, h };
    }

    _horizontalBand(visible) {
        return this._centred(visible, visible.w * 0.9, Math.min(visible.h, visible.w * 0.6));
    }

    _verticalBand(visible) {
        return this._centred(visible, Math.min(visible.w, visible.h * 0.6), visible.h * 0.9);
    }

    _centred(visible, w, h) {
        const width = Math.max(1, Math.round(w));
        const height = Math.max(1, Math.round(h));

        return {
            x: Math.round(visible.x + (visible.w - width) / 2),
            y: Math.round(visible.y + (visible.h - height) / 2),
            w: width,
            h: height,
        };
    }

    _sizeOverlay() {
        const video = this.videoTarget;
        if (video.videoWidth && (this.overlayTarget.width !== video.videoWidth || this.overlayTarget.height !== video.videoHeight)) {
            this.overlayTarget.width = video.videoWidth;
            this.overlayTarget.height = video.videoHeight;
        }
    }

    // Corner marks of the horizontal band: where to hold the code (zbar decodes nothing outside it)
    _drawGuide(ctx) {
        const video = this.videoTarget;
        if (!video.videoWidth) return;

        const band = this._horizontalBand(this._visibleRect());
        const arm = Math.min(band.w, band.h) * 0.18;
        ctx.save();
        const width = Math.max(2, video.videoWidth / 220);
        ctx.beginPath();
        [[band.x, band.y, 1, 1], [band.x + band.w, band.y, -1, 1], [band.x, band.y + band.h, 1, -1], [band.x + band.w, band.y + band.h, -1, -1]]
            .forEach(([x, y, dx, dy]) => {
                ctx.moveTo(x + dx * arm, y);
                ctx.lineTo(x, y);
                ctx.lineTo(x, y + dy * arm);
            });
        // White on a dark outline: visible on a white box and on a dark one
        ctx.lineCap = 'round';
        ctx.lineWidth = width * 2;
        ctx.strokeStyle = 'rgba(0, 0, 0, 0.45)';
        ctx.stroke();
        ctx.lineWidth = width;
        ctx.strokeStyle = 'rgba(255, 255, 255, 0.95)';
        ctx.stroke();
        ctx.restore();
    }

    _handleBarcodes(barcodes, frame, ctx) {
        const barcode = barcodes[0];
        const code = barcode.rawValue;
        const now = Date.now();
        // A frame that reads two different codes is not trusted at all: two boxes in view, or a misread
        const sameCodeOnly = barcodes.every(b => b.rawValue === code);

        // The camera sees a code, so it is roughly in focus: no kick for a while
        this.nextFocusKickAt = Math.max(this.nextFocusKickAt, now + FOCUS_KICK_FIRST_MS);
        // The player found the distance; the hint would only cover the picture now
        if (this.hasHintTarget) {
            this.hintTarget.classList.add('d-none');
        }

        if (barcode.cornerPoints && barcode.cornerPoints.length > 0) {
            ctx.beginPath();
            ctx.moveTo(frame.x + barcode.cornerPoints[0].x, frame.y + barcode.cornerPoints[0].y);
            for (let i = 1; i < barcode.cornerPoints.length; i++) {
                ctx.lineTo(frame.x + barcode.cornerPoints[i].x, frame.y + barcode.cornerPoints[i].y);
            }
            ctx.closePath();
            ctx.lineWidth = 3;
            ctx.strokeStyle = '#fe4042';
            ctx.stroke();
        }

        if (!sameCodeOnly) {
            barcodes.filter(b => this._Barcoder.validate(b.rawValue)).forEach(b => this.codeSeenAt.set(b.rawValue, now));
            return;
        }

        if (!this._Barcoder.validate(code)) {
            return;
        }

        this._recordSeen(code, barcode.quality);

        // zbar's quality = how many scanlines read the code; the browser's detector reports none
        if (this.engine === 'zbar' && !(barcode.quality >= 3)) {
            return;
        }

        this.codeSeenAt.set(code, now);
        this.scanBuffer.push(code);
        if (this.scanBuffer.length > 30) {
            this.scanBuffer.shift();
        }

        for (const [other, seenAt] of this.codeSeenAt) {
            if (other !== code && now - seenAt < COMPETING_CODE_MS) {
                return;
            }
        }

        const count = this.scanBuffer.filter(c => c === code).length;
        if (count < this._readsNeeded(code)) {
            return;
        }

        this.scanBuffer = [];

        if (this.continuousValue) {
            if (this._acceptContinuous(code, now)) {
                this._recordAccepted(code, now);
            }
        } else {
            this._recordAccepted(code, now);
            this.inputTarget.value = code;
            this.inputTarget.dispatchEvent(new Event('change', { bubbles: true }));
            this._emitScanned(code);
            this.stopScanning();
        }
    }

    /**
     * zbar's wrong reads are rare and change from frame to frame, so a few agreeing frames filter them (README §5.3);
     * codes starting with 0 (where wrong reads cluster) and EAN-8 need more. The browser's detector keeps its 10 -
     * its misreads repeat, more frames would not catch them (`EanList` does when a code is stored).
     */
    _readsNeeded(code) {
        if (this.engine !== 'zbar') {
            return 10;
        }

        return code.charAt(0) === '0' || code.length === 8 ? 8 : 4;
    }

    /**
     * Decode each camera frame once: requestAnimationFrame can fire twice for the same frame, and a frame
     * decoded twice would count twice towards the matching reads.
     */
    _onNextFrame(callback) {
        const video = this.videoTarget;

        if (typeof video.requestVideoFrameCallback !== 'function') {
            requestAnimationFrame(callback);
            return;
        }

        let called = false;
        const run = () => {
            if (!called) {
                called = true;
                callback();
            }
        };
        const handle = video.requestVideoFrameCallback(run);

        // Never stall when a browser stops reporting frames - fall back to decoding what is on screen
        window.setTimeout(() => {
            if (!called) {
                video.cancelVideoFrameCallback(handle);
                run();
            }
        }, 250);
    }

    stopScanning() {
        if (this.hasResultsTarget) {
            this.resultsTarget.classList.remove('d-none');
        }

        this.scanning = false;
        // A camera still opening is closed as soon as it arrives
        this.cameraSession = (this.cameraSession || 0) + 1;
        this.wrapperTarget.classList.add('d-none');
        this.toggleButtonTarget.classList.remove('active');
        this._stopDebug();

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
        this._cropCanvases = null;

        // Clear references to allow garbage collection
        this._resetCameraState();
        this._updateHud();
        this.paused = false;
        this.acceptedAt = new Map();
    }

    // --- ?scan-debug=1: what the camera and the decoder do, for a screenshot (README §5.1) ---

    _urlParam(name) {
        try {
            return new URLSearchParams(window.location.search).get(name);
        } catch (e) {
            return null;
        }
    }

    _recordDecode(ms, frame) {
        this.stats.frames++;
        this.stats.decodeMs.push(ms);
        if (this.stats.decodeMs.length > 30) {
            this.stats.decodeMs.shift();
        }
        this.stats.region = frame;
    }

    _recordSeen(code, quality) {
        const seen = this.stats.seen.get(code) || { count: 0, quality: null };
        seen.count++;
        seen.quality = quality === undefined ? null : quality;
        this.stats.seen.delete(code);
        this.stats.seen.set(code, seen);
        if (this.stats.seen.size > 4) {
            this.stats.seen.delete(this.stats.seen.keys().next().value);
        }
    }

    _recordAccepted(code, now) {
        const first = this.stats.accepted;
        this.stats.accepted = { code, afterMs: first ? first.afterMs : now - this.startedAt, count: (first ? first.count : 0) + 1 };
    }

    _startDebug() {
        if (!this.debug) return;

        if (!this.debugPanel) {
            this.debugPanel = document.createElement('pre');
            this.debugPanel.className = 'barcode-scanner-debug';
            this.wrapperTarget.insertAdjacentElement('afterend', this.debugPanel);
        }
        this.debugPanel.classList.remove('d-none');

        if (this.debugModel === undefined) {
            this.debugModel = null;
            deviceModel().then(model => { this.debugModel = model; });
        }

        // Labels are there once the camera is allowed - for every browser, not only Android's pick
        if (!this.cameraLabels && navigator.mediaDevices.enumerateDevices) {
            navigator.mediaDevices.enumerateDevices()
                .then(devices => { this.cameraLabels = devices.filter(d => d.kind === 'videoinput').map(d => d.label || '(no label)'); })
                .catch(() => {});
        }

        window.clearInterval(this.debugTimer);
        this.debugTimer = window.setInterval(() => this._renderDebug(), 500);
        this._renderDebug();
    }

    _stopDebug() {
        window.clearInterval(this.debugTimer);
        this.debugTimer = null;
        if (this.debugPanel) {
            this.debugPanel.classList.add('d-none');
        }
    }

    _renderDebug() {
        if (!this.debugPanel || !this.scanning) return;

        const video = this.videoTarget;
        const settings = this._settings();
        const caps = this.trackCapabilities;
        const range = (r) => (r && r.min !== undefined ? `${round(r.min)}-${round(r.max)}${r.step ? ` step ${round(r.step)}` : ''}` : '-');
        const round = (n) => (typeof n === 'number' ? Math.round(n * 100) / 100 : n);
        const decode = this.stats.decodeMs;
        const avg = decode.length ? Math.round(decode.reduce((a, b) => a + b, 0) / decode.length) : '-';
        const region = this.stats.region;
        const ago = (at) => `${((Date.now() - at) / 1000).toFixed(1)} s ago`;

        const lines = [
            `engine: ${this.engine || 'loading'}${this.forcedEngine ? ` (forced ${this.forcedEngine})` : ''}${region && this.engine === 'zbar' ? `, band ${region.w}x${region.h}${region.vertical ? ' upright' : ''}` : ''}`,
            `camera: ${this.videoTrack ? this.videoTrack.label : '-'} (${this.cameraPick || '-'})`,
            `cameras: ${(this.cameraLabels || []).join(' | ') || '-'}`,
            `video: ${video.videoWidth}x${video.videoHeight}, settings ${settings.width}x${settings.height} @${round(settings.frameRate)} fps, max ${caps.width ? caps.width.max : '-'}x${caps.height ? caps.height.max : '-'}`,
            `zoom: ${this.zoomCapabilities ? this.currentZoom : 'not supported'} (settings ${round(settings.zoom)}, range ${range(caps.zoom)})`,
            `focus: ${settings.focusMode || '-'} [${(caps.focusMode || []).join(', ')}], distance ${round(settings.focusDistance)} (range ${range(caps.focusDistance)})`,
            `last refocus: ${this.lastFocusKick ? `${this.lastFocusKick.reason} ${ago(this.lastFocusKick.at)}` : '-'}`,
            `torch: ${this.torchSupported ? (this.torchOn ? 'on' : 'off') : 'not supported'}`,
            `frames: ${this.stats.frames}, decode ${avg} ms`,
            `seen: ${[...this.stats.seen].map(([code, s]) => `${code} x${s.count}${s.quality !== null ? ` q${s.quality}` : ''}`).join(', ') || '-'}`,
            `accepted: ${this.stats.accepted ? `${this.stats.accepted.code} (${this.stats.accepted.count}x), first after ${(this.stats.accepted.afterMs / 1000).toFixed(1)} s` : '-'}`,
            `running: ${((Date.now() - this.startedAt) / 1000).toFixed(0)} s`,
            `device: ${this.debugModel || '-'}`,
            `browser: ${browserDiagnostics()}`,
        ];

        this.debugPanel.textContent = lines.join('\n');
    }

    _readStorage(storage, key) {
        try { return storage.getItem(key); } catch (e) { return null; }
    }

    _writeStorage(storage, key, value) {
        try {
            if (value === null) {
                storage.removeItem(key);
            } else {
                storage.setItem(key, value);
            }
        } catch (e) { /* private mode */ }
    }

    // --- Continuous mode helpers (multiscan) ---

    /**
     * @returns {boolean} whether the code was handed over (false = inside the cooldown, dropped)
     */
    _acceptContinuous(code, now) {
        const last = this.acceptedAt.get(code) || 0;

        if (now - last < this.cooldownMsValue) {
            return false;
        }

        this.acceptedAt.set(code, now);
        this.inputTarget.value = code;
        this.feedback('read');
        this._emitScanned(code);

        return true;
    }

    _emitScanned(code) {
        // Dispatch custom event for other controllers to listen
        this.element.dispatchEvent(new CustomEvent('barcode-scanner:scanned', {
            detail: { code: code },
            bubbles: true
        }));
    }

    /**
     * Stop detecting (camera stays on) - the host is showing a sheet for the last result.
     */
    pause() {
        this.paused = true;
    }

    resume() {
        this.paused = false;

        if (isNativeApp() && this.continuousValue && this.nativeAwaitingResume) {
            // The native scanner is single-shot: open it again for the next box
            this.nativeAwaitingResume = false;
            this.openNativeScanner();
        }
    }

    /**
     * kind: read (a code was decoded), found, duplicate, unknown, invalid, error
     */
    feedback(kind) {
        const patterns = {
            read: [40],
            found: [],
            duplicate: [30, 40, 30],
            unknown: [200],
            invalid: [60, 40, 60],
            error: [],
        };
        const tones = {
            read: [[1320, 60]],
            found: [],
            duplicate: [[330, 120]],
            unknown: [[660, 90], [440, 140]],
            invalid: [[330, 120]],
            error: [],
        };

        const pattern = patterns[kind] || [];
        if (pattern.length && typeof navigator.vibrate === 'function') {
            try { navigator.vibrate(pattern); } catch (e) { /* not supported */ }
        }

        (tones[kind] || []).reduce((delay, [frequency, duration]) => {
            window.setTimeout(() => this._beep(frequency, duration), delay);
            return delay + duration + 30;
        }, 0);

        this.flash(kind);
    }

    flash(kind) {
        if (!this.hasFlashTarget) {
            return;
        }

        const classes = { read: 'is-read', found: 'is-found', duplicate: 'is-duplicate', unknown: 'is-unknown', invalid: 'is-unknown' };
        const cls = classes[kind];
        if (!cls) {
            return;
        }

        this.flashTarget.classList.remove('is-read', 'is-found', 'is-duplicate', 'is-unknown');
        // Restart the CSS animation even when the same class is applied twice in a row
        void this.flashTarget.offsetWidth;
        this.flashTarget.classList.add(cls);
        window.setTimeout(() => this.flashTarget.classList.remove(cls), 450);
    }

    toggleSound() {
        this.soundEnabled = !this.soundEnabled;
        try { window.localStorage.setItem(STORAGE_SOUND, this.soundEnabled ? '1' : '0'); } catch (e) { /* private mode */ }
        this._updateSoundButton();

        if (this.soundEnabled) {
            this._beep(1320, 60);
        }
    }

    _readSoundPreference() {
        try { return window.localStorage.getItem(STORAGE_SOUND) !== '0'; } catch (e) { return true; }
    }

    _updateSoundButton() {
        if (!this.hasSoundButtonTarget) {
            return;
        }

        const icon = this.soundButtonTarget.querySelector('i');
        if (icon) {
            icon.className = this.soundEnabled ? 'bi-volume-up' : 'bi-volume-mute';
        }
        this.soundButtonTarget.classList.toggle('active', this.soundEnabled);
        this.soundButtonTarget.setAttribute('aria-pressed', this.soundEnabled ? 'true' : 'false');
    }

    _beep(frequency, duration) {
        if (!this.soundEnabled) {
            return;
        }

        try {
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            if (!AudioContextClass) {
                return;
            }

            if (!this.audioContext) {
                this.audioContext = new AudioContextClass();
            }

            // Autoplay policy: a context created before any user gesture stays suspended
            if (this.audioContext.state === 'suspended') {
                this.audioContext.resume().catch(() => {});
                if (this.audioContext.state === 'suspended') {
                    return;
                }
            }

            const oscillator = this.audioContext.createOscillator();
            const gain = this.audioContext.createGain();
            oscillator.type = 'sine';
            oscillator.frequency.value = frequency;
            gain.gain.value = 0.08;
            oscillator.connect(gain);
            gain.connect(this.audioContext.destination);
            const now = this.audioContext.currentTime;
            oscillator.start(now);
            gain.gain.setValueAtTime(0.08, now + Math.max(0, duration / 1000 - 0.02));
            gain.gain.linearRampToValueAtTime(0, now + duration / 1000);
            oscillator.stop(now + duration / 1000);
        } catch (e) {
            // Sound is a nicety; never break scanning over it
        }
    }

    // --- Native App Scanner Bridge Methods ---

    /**
     * Opens the native barcode scanner for iOS or Android apps.
     * The native app will call onNativeScanResult() or onNativeScanCancelled() callbacks.
     */
    openNativeScanner() {
        this._claimNativeCallbacks();
        this.toggleButtonTarget.classList.add('active');

        if (this.inputTarget.value !== '') {
            this.inputTarget.value = '';
        }

        if (this.hasResultsTarget) {
            this.resultsTarget.classList.add('d-none');
        }

        if (nativePlatform() === 'ios') {
            // iOS: Use WKScriptMessageHandler bridge
            if (window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.scanner) {
                window.webkit.messageHandlers.scanner.postMessage({ action: 'open' });
            } else {
                console.error('iOS scanner bridge not available');
                // Fallback to web scanner if bridge not available
                this.initWebScanner();
            }
        } else if (nativePlatform() === 'android') {
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
            this._noCameraNotice();
            return;
        }

        this._startCamera();
    }

    /**
     * Callback for successful native barcode scan.
     * Called by native iOS/Android code when a barcode is detected.
     */
    handleNativeScanResult(code) {
        this.toggleButtonTarget.classList.remove('active');

        if (this.continuousValue) {
            // Single-shot native scanner: hand the code over and re-open it when the host resumes.
            // A re-scan inside the cooldown never reaches the host, so re-open right away.
            if (this._acceptContinuous(code, Date.now())) {
                this.nativeAwaitingResume = true;
            } else {
                this.feedback('duplicate');
                this.openNativeScanner();
            }
            return;
        }

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
