import { Controller } from '@hotwired/stimulus';
import { HelperBudget, ReadAcceptance, canonicalCode } from '../barcode_acceptance.js';

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
// Every camera frame is decoded only while a reader saw a code in the last second; otherwise at most 10 a second.
// Decoding every frame kept a phone's main core busy for as long as the camera was open - an iPhone got hot after
// ~2 minutes of multiscan (2026-10-18, README §5.8).
const ACTIVE_FOR_MS = 1000;
const RESTING_INTERVAL_MS = 100;
const FORMATS = ['ean_8', 'ean_13'];
const ZBAR_SCRIPTS = [
    'https://cdn.jsdelivr.net/npm/@undecaf/zbar-wasm@0.9.15/dist/index.js',
    // Depends on zbar-wasm, loaded second
    'https://cdn.jsdelivr.net/npm/@undecaf/barcode-detector-polyfill@0.9.21/dist/index.js',
];

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

        // A tab closed (or a page left) with the camera on still reports its session
        this._boundPageHide = () => this._reportSession();
        window.addEventListener('pagehide', this._boundPageHide);

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
        if (!this.hasToggleButtonTarget) {
            return;
        }

        this.stopScanning();
        window.removeEventListener('barcode-scan:close', this._boundStopScanning);
        window.removeEventListener('pagehide', this._boundPageHide);
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
        if (window.isNativeApp) {
            this.openNativeScanner();
            return;
        }

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            console.error('Camera API not available');
            alert('Camera is not available in this browser. Please try reloading the page.');
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
        // ?scanner=zbar / ?scanner=native forces one decoder (support and testing, README §5.5)
        const forced = this._urlParam('scanner');
        this.forcedEngine = forced === 'zbar' || forced === 'native' ? forced : null;

        this.wrapperTarget.classList.remove('d-none');
        const session = ++this.cameraSession;
        // Download the decoders while the camera opens, not after
        this._warmDecoders();

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
        this.sessionReported = false;
        this._loadDeviceModel();
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
        if (remembered === 'facing' || !/Android/i.test(navigator.userAgent)) {
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
        this.engine = null;
        this.engines = {};
        this.zbarBudget = null;
        this.zbarPlanned = false;
        this.frameCounterMoves = false;
        this.lastFocusKick = null;
        this.nextFocusKickAt = 0;
        this.stats = { frames: 0, decodeMs: [], seen: new Map(), accepted: null, acceptedBy: null };
    }

    // --- Decoding ---

    /**
     * Which readers a session runs (README §5.7). Where the browser reads EAN-13 itself (Google's engine in Android
     * Chrome and Samsung Internet, Apple's in Chrome on macOS) both its reader and our zbar run side by side: Google's
     * reads blurrier frames, zbar never makes its misreads (4005555011897 read as 0045555011897), so either can read
     * alone, both agreeing reads fastest, and a code one of them contradicts waits. zbar alone everywhere else (iOS,
     * Firefox, Chrome on Windows/Linux). zbar alone on Android (2026-10-04 to 10-06) cost about half of the Android
     * scans - at 640x480 from the wrong lens; Google's alone (to 2026-10-19) still misread a box at 1080p.
     * A forced native reader where the browser has none falls back to zbar (the debug panel says so).
     *
     * @returns {Promise<{native: boolean, zbar: boolean}>}
     */
    async _enginePlan() {
        const native = this.forcedEngine !== 'zbar' && await this._nativeReadsEan();

        return { native, zbar: !native || this.forcedEngine !== 'native' };
    }

    _nativeReadsEan() {
        if (!this._nativeReadsEanPromise) {
            this._nativeReadsEanPromise = (async () => {
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
            })();
        }

        return this._nativeReadsEanPromise;
    }

    _warmDecoders() {
        this._loadBarcoder().catch(() => {});
        this._enginePlan().then(plan => plan.zbar && this._loadZbar()).catch(() => {});
    }

    async _loadBarcoder() {
        if (!this._Barcoder) {
            const mod = await import('barcoder');
            this._Barcoder = mod.default;
        }

        return this._Barcoder;
    }

    /**
     * @returns {Promise<Function|null>} zbar's BarcodeDetector, null when it could not be downloaded (tried again next time)
     */
    _loadZbar() {
        if (!this._zbarPromise) {
            this._zbarPromise = (async () => {
                try {
                    for (const src of ZBAR_SCRIPTS) {
                        await this._loadScript(src);
                    }
                } catch (e) {
                    console.error('Barcode decoder could not be loaded:', e);
                }

                if (typeof barcodeDetectorPolyfill !== 'undefined' && barcodeDetectorPolyfill.BarcodeDetectorPolyfill) {
                    return barcodeDetectorPolyfill.BarcodeDetectorPolyfill;
                }

                this._zbarPromise = null;
                return null;
            })();
        }

        return this._zbarPromise;
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
            script.onerror = (e) => {
                // A failed tag would make the next try "already loaded"
                script.remove();
                reject(e);
            };
            document.head.appendChild(script);
        });
    }

    _engineState(name, detector) {
        this.acceptance.addReader(name);

        return { detector, frames: 0, decodeMs: [], region: null, lastDecodeAt: -Infinity, lastFrameCount: undefined };
    }

    _engineName() {
        const native = !!this.engines.native;
        const zbar = !!this.engines.zbar;

        return native && zbar ? 'dual' : (native ? 'native' : (zbar ? 'zbar' : 'none'));
    }

    async scanLoop() {
        // A stop followed by a quick restart must not leave the old loop running next to the new one
        const generation = this.scanGeneration = (this.scanGeneration || 0) + 1;
        const current = () => this.scanning && generation === this.scanGeneration;

        const [, plan] = await Promise.all([this._loadBarcoder(), this._enginePlan()]);
        if (!current()) return;

        this.engines = {};
        this.acceptance = new ReadAcceptance(this.startedAt);
        if (plan.native) {
            this.engines.native = this._engineState('native', new window.BarcodeDetector({ formats: FORMATS }));
        }
        if (plan.zbar) {
            const zbar = this._loadZbar().then(Zbar => {
                if (current()) {
                    if (Zbar) {
                        this.engines.zbar = this._engineState('zbar', new Zbar({ formats: FORMATS }));
                    }
                    this.engine = this._engineName();
                }
            });
            // Next to Google's reader zbar joins once downloaded; alone it is the reader, so wait for it
            if (!plan.native) {
                await zbar;
                if (!current()) return;
            }
        }
        this.engine = plan.native && plan.zbar && !this.engines.zbar ? 'native' : this._engineName();
        this.zbarPlanned = plan.native && plan.zbar;

        if (this.engine === 'none') {
            console.error('BarcodeDetector is not available');
            return;
        }

        const ctx = this.overlayTarget.getContext('2d');
        // The reader that paces the session and draws the overlay: Google's where it runs, else zbar
        const primary = plan.native ? 'native' : 'zbar';
        // The first second at full pace: the player is just pointing the camera
        this.lastActivityAt = performance.now();
        // Next to Google's reader, zbar only within a budget that keeps Google's at its own pace (HelperBudget)
        this.zbarBudget = plan.native && plan.zbar ? new HelperBudget() : null;

        // One loop per reader, each at its own pace - waiting for both on every frame slowed a mid-range phone's
        // Google reader five times
        const loop = (name) => {
            const step = async (fresh = true) => {
                if (!current()) {
                    return;
                }

                try {
                    await this._scanStep(name, primary, ctx, current, fresh);
                } catch (error) {
                    // One bad frame must never end the loop - the scanner would stop reading without a word
                    console.error('Barcode scan step failed:', error);
                }

                if (current()) {
                    this._onNextFrame(step);
                }
            };

            step();
        };

        loop(primary);
        if (this.zbarBudget) {
            loop('zbar');
        }
    }

    /**
     * One frame of one reader's loop (scanLoop). `current` = this session is still the one running: checked again
     * after every wait, since a stop or a quick restart replaces the state this step works on.
     */
    async _scanStep(name, primary, ctx, current, fresh) {
        const engine = this.engines[name];
        const isPrimary = name === primary;
        const video = this.videoTarget;

        // Nothing to decode while paused (multiscan shows a result) or while the tab is hidden - the frame
        // fallback timer would otherwise keep decoding in the background
        if (this.paused || document.hidden) {
            return;
        }

        if (isPrimary) {
            if (Date.now() >= this.nextFocusKickAt) {
                this.kickFocus();
            }
            this._refreshOverlay(ctx);
        }

        // zbar next to Google's reader may not be downloaded yet - this loop then only waits
        if (!engine || !video.videoWidth || !video.videoHeight) {
            return;
        }

        const now = performance.now();
        const due = now - this.lastActivityAt < ACTIVE_FOR_MS || now - engine.lastDecodeAt >= RESTING_INTERVAL_MS;
        if (!due || (!isPrimary && !this.zbarBudget.mayRun(now)) || !this._newFrame(engine, fresh)) {
            return;
        }

        engine.lastDecodeAt = now;
        const frame = name === 'native'
            ? { source: video, x: 0, y: 0, w: video.videoWidth, h: video.videoHeight, vertical: false }
            : this._band(engine);
        const barcodes = await this._decode(name, engine, frame);
        const cost = performance.now() - now;

        if (!current()) {
            return;
        }

        if (isPrimary) {
            this.stats.frames++;
            this.stats.decodeMs.push(cost);
            if (this.stats.decodeMs.length > 30) {
                this.stats.decodeMs.shift();
            }
            if (this.zbarBudget) {
                this.zbarBudget.primaryDecoded(cost);
            }
        } else {
            this.zbarBudget.helperRan(cost, performance.now());
        }

        // Paused meanwhile: multiscan is showing the last result
        if (barcodes.length > 0 && !this.paused) {
            this._handleBarcodes(name, barcodes, frame, ctx);
        }
    }

    async _decode(name, engine, frame) {
        const start = performance.now();
        let barcodes = [];
        try {
            barcodes = await engine.detector.detect(frame.source);
        } catch (error) {
            console.error('Barcode detection error:', error);
        }
        const ms = performance.now() - start;

        engine.frames++;
        engine.decodeMs.push(ms);
        if (engine.decodeMs.length > 30) {
            engine.decodeMs.shift();
        }
        engine.region = frame;

        return barcodes;
    }

    /**
     * zbar gets a centred band of what the player sees, at full resolution - a whole 1080p frame would cost it ~6x a
     * 640x480 one - alternating with an upright band where the picture is taller than wide, so a box held sideways
     * reads. Google's reader gets the whole frame (it asks for 720p-1080p and reads any rotation).
     *
     * @returns {{source: ImageData, x: number, y: number, w: number, h: number, vertical: boolean}}
     */
    _band(engine) {
        const video = this.videoTarget;
        const visible = this._visibleRect();
        const vertical = visible.h >= visible.w && engine.frames % 2 === 1;
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

    /**
     * @returns {boolean} whether it resized the overlay, which also cleared it
     */
    _sizeOverlay() {
        const video = this.videoTarget;
        if (video.videoWidth && (this.overlayTarget.width !== video.videoWidth || this.overlayTarget.height !== video.videoHeight)) {
            this.overlayTarget.width = video.videoWidth;
            this.overlayTarget.height = video.videoHeight;
            return true;
        }

        return false;
    }

    // The guide is redrawn only when it moved or a code's outline was drawn over it - the overlay is a full-size
    // canvas the phone composites again after every change
    _refreshOverlay(ctx) {
        const resized = this._sizeOverlay();
        // Where the guide belongs reads the layout - not on every frame, twice a second is enough for a rotation
        const now = performance.now();
        let guide = this.overlayGuide;
        if (resized || guide === null || guide === undefined || now - (this.overlayGuideAt || 0) >= 500) {
            const band = this._horizontalBand(this._visibleRect());
            guide = `${band.x},${band.y},${band.w},${band.h}`;
            this.overlayGuideAt = now;
        }
        if (!resized && !this.overlayMarked && guide === this.overlayGuide) {
            return;
        }

        ctx.clearRect(0, 0, this.overlayTarget.width, this.overlayTarget.height);
        this._drawGuide(ctx);
        this.overlayMarked = false;
        this.overlayGuide = guide;
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

    _handleBarcodes(name, barcodes, frame, ctx) {
        const barcode = barcodes[0];
        const code = canonicalCode(barcode.rawValue);
        const now = Date.now();
        // A frame that reads two different codes is not trusted at all: two boxes in view, or a misread
        const sameCodeOnly = barcodes.every(b => canonicalCode(b.rawValue) === code);

        // Something to read: every frame again - unless it is only the box multiscan just took, still in view
        if (!this.continuousValue || barcodes.some(b => now - (this.acceptedAt.get(canonicalCode(b.rawValue)) || 0) >= this.cooldownMsValue)) {
            this.lastActivityAt = performance.now();
        }

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
            this.overlayMarked = true;
        }

        if (!sameCodeOnly) {
            this.acceptance.seenTogether(name, barcodes.map(b => b.rawValue).filter(c => this._Barcoder.validate(c)), now);
            return;
        }

        if (!this._Barcoder.validate(barcode.rawValue)) {
            return;
        }

        this._recordSeen(name, code, barcode.quality, now);

        const acceptedBy = this.acceptance.read(name, code, barcode.quality, now);
        if (acceptedBy === null) {
            return;
        }

        if (this.continuousValue) {
            if (this._acceptContinuous(code, now)) {
                this._recordAccepted(code, now, acceptedBy);
            }
        } else {
            this._recordAccepted(code, now, acceptedBy);
            this.inputTarget.value = code;
            this.inputTarget.dispatchEvent(new Event('change', { bubbles: true }));
            this._emitScanned(code);
            this.stopScanning();
        }
    }

    /**
     * Decode each camera frame once: requestAnimationFrame can fire twice for the same frame, and a frame
     * decoded twice would count twice towards the matching reads.
     */
    _onNextFrame(callback) {
        const video = this.videoTarget;

        if (typeof video.requestVideoFrameCallback !== 'function') {
            requestAnimationFrame(() => callback(false));
            return;
        }

        let called = false;
        let timer = null;
        const run = (fresh) => {
            if (!called) {
                called = true;
                window.clearTimeout(timer);
                callback(fresh);
            }
        };
        const handle = video.requestVideoFrameCallback(() => run(true));

        // Never stall when a browser stops reporting frames - fall back to decoding what is on screen (only if it
        // changed: `_newFrame()`)
        timer = window.setTimeout(() => {
            if (!called) {
                video.cancelVideoFrameCallback(handle);
                run(false);
            }
        }, 250);
    }

    /**
     * Whether the video shows a frame this reader has not decoded yet. A frame the browser reported is new; without
     * that report (the 250 ms fallback, requestAnimationFrame) the browser's frame counter decides - a stalled video
     * decoded again and again would count one frame as many agreeing reads. A counter that never moves is not
     * trusted (some browsers do not count camera frames), and the frame is decoded as before.
     */
    _newFrame(engine, fresh) {
        let count = null;
        try {
            const quality = typeof this.videoTarget.getVideoPlaybackQuality === 'function' ? this.videoTarget.getVideoPlaybackQuality() : null;
            count = quality && typeof quality.totalVideoFrames === 'number' ? quality.totalVideoFrames : null;
        } catch (e) { /* not supported */ }

        const last = engine.lastFrameCount;
        engine.lastFrameCount = count;
        if (count !== null && last !== undefined && last !== null && count !== last) {
            this.frameCounterMoves = true;
        }

        return fresh || count === null || !this.frameCounterMoves || count !== last;
    }

    stopScanning() {
        if (this.hasResultsTarget) {
            this.resultsTarget.classList.remove('d-none');
        }

        this._reportSession();
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
        this.overlayGuide = null;
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

    // Per code: how often each reader read it and when first - the most recent few codes
    _recordSeen(name, code, quality, now) {
        const seen = this.stats.seen.get(code) || { native: 0, zbar: 0, quality: null, firstAt: {} };
        seen[name]++;
        if (name === 'zbar') {
            seen.quality = quality === undefined ? null : quality;
        }
        if (seen.firstAt[name] === undefined) {
            seen.firstAt[name] = now;
        }
        this.stats.seen.delete(code);
        this.stats.seen.set(code, seen);
        if (this.stats.seen.size > 6) {
            this.stats.seen.delete(this.stats.seen.keys().next().value);
        }
    }

    _recordAccepted(code, now, by) {
        const first = this.stats.accepted;
        this.stats.accepted = { code, afterMs: first ? first.afterMs : now - this.startedAt, count: (first ? first.count : 0) + 1 };

        if (!first) {
            // Which reader decided, and how far each one had got with the code by then (README §5.7)
            const seen = this.stats.seen.get(code);
            this.stats.acceptedBy = by;
            this.stats.firstSeenMs = {};
            ['native', 'zbar'].forEach(name => {
                const at = seen ? seen.firstAt[name] : undefined;
                this.stats.firstSeenMs[name] = at === undefined ? null : at - this.startedAt;
            });
        }
    }

    _seenText() {
        return [...this.stats.seen].map(([code, s]) => {
            const parts = [];
            if (this.engines.native || s.native) parts.push(`native x${s.native}`);
            if (this.engines.zbar || s.zbar) parts.push(`zbar x${s.zbar}${s.quality !== null ? ` q${s.quality}` : ''}`);
            return `${code} ${parts.join(' ')}`;
        }).join(', ');
    }

    _engineReport(name) {
        const engine = this.engines[name];
        if (!engine) {
            return null;
        }

        const decode = engine.decodeMs;

        return {
            frames: engine.frames,
            decodeMs: decode.length ? Math.round(decode.reduce((a, b) => a + b, 0) / decode.length) : null,
            firstSeenMs: this.stats.firstSeenMs ? this.stats.firstSeenMs[name] : null,
            soloMs: this.acceptance.soloMs(name),
            // Google's reader alone, per frame; how far zbar had to step back beside it (HelperBudget)
            baselineMs: name === 'native' && this.zbarBudget && this.zbarBudget.baselineMs !== null ? Math.round(this.zbarBudget.baselineMs) : null,
            maxBackoff: name === 'zbar' && this.zbarBudget ? this.zbarBudget.maxBackoff : null,
        };
    }

    // The phone model, where the browser tells it (Chromium's client hints - user agents no longer carry it)
    _loadDeviceModel() {
        if (this.deviceModel !== undefined) return;
        this.deviceModel = null;
        if (navigator.userAgentData && typeof navigator.userAgentData.getHighEntropyValues === 'function') {
            navigator.userAgentData.getHighEntropyValues(['model', 'platformVersion'])
                .then(values => { this.deviceModel = [values.model, values.platform, values.platformVersion].filter(Boolean).join(' '); })
                .catch(() => {});
        }
    }

    /**
     * One report per camera session (README §5.1): which phone, camera and reader, and whether a code was read -
     * a scan that never reads leaves no other trace. Sent once, when the camera stops or the page goes away.
     */
    _reportSession() {
        if (!this.scanning || this.sessionReported || typeof navigator.sendBeacon !== 'function') return;
        this.sessionReported = true;

        const settings = this._settings();
        const caps = this.trackCapabilities || {};
        const decode = this.stats.decodeMs;
        const accepted = this.stats.accepted;
        const video = this.videoTarget;

        const report = {
            page: window.location.pathname,
            engine: this.engine || null,
            forced: this.forcedEngine,
            debug: this.debug,
            continuous: this.continuousValue,
            outcome: accepted ? 'read' : 'no-read',
            reads: accepted ? accepted.count : 0,
            firstReadMs: accepted ? Math.round(accepted.afterMs) : null,
            durationMs: Date.now() - this.startedAt,
            frames: this.stats.frames,
            decodeMs: decode.length ? Math.round(decode.reduce((a, b) => a + b, 0) / decode.length) : null,
            seenCodes: this.stats.seen.size,
            // Both readers side by side (README §5.7): who decided, each one's own numbers, every code read
            acceptedBy: this.stats.acceptedBy,
            native: this._engineReport('native'),
            zbar: this._engineReport('zbar'),
            zbarMissing: !!this.zbarPlanned && !this.engines.zbar,
            seen: this._seenText().slice(0, 300),
            camera: this.videoTrack ? this.videoTrack.label : null,
            cameraPick: this.cameraPick,
            video: video.videoWidth ? `${video.videoWidth}x${video.videoHeight}` : null,
            zoom: this.zoomCapabilities ? this.currentZoom : null,
            focusMode: settings.focusMode || null,
            focusModes: caps.focusMode || [],
            focusDistanceMin: caps.focusDistance && typeof caps.focusDistance.min === 'number' ? caps.focusDistance.min : null,
            torch: this.torchSupported,
            model: this.deviceModel || null,
        };

        try {
            navigator.sendBeacon('/-/scan-session', JSON.stringify(report));
        } catch (e) {
            // Never let a report break the scanner
        }
    }

    _startDebug() {
        if (!this.debug) return;

        if (!this.debugPanel) {
            this.debugPanel = document.createElement('div');
            this.debugPanel.className = 'barcode-scanner-debug';
            // A pinned bar can cover the panel's end (multiscan on a phone), so the text can be pasted into a mail
            const copy = document.createElement('button');
            copy.type = 'button';
            copy.className = 'barcode-scanner-debug-copy';
            copy.textContent = 'Copy';
            copy.addEventListener('click', () => this._copyDebug(copy));
            this.debugText = document.createElement('pre');
            this.debugPanel.append(copy, this.debugText);
            this.wrapperTarget.insertAdjacentElement('afterend', this.debugPanel);
        }
        this.debugPanel.classList.remove('d-none');

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
        const ago = (at) => `${((Date.now() - at) / 1000).toFixed(1)} s ago`;
        const zbar = this.engines.zbar;
        const engineLine = (name) => {
            const engine = this._engineReport(name);
            if (!engine) return null;
            const parts = [`${name}: ${engine.frames} frames, decode ${engine.decodeMs === null ? '-' : engine.decodeMs} ms`];
            if (engine.firstSeenMs !== null) parts.push(`saw it after ${(engine.firstSeenMs / 1000).toFixed(1)} s`);
            if (engine.soloMs !== null) parts.push(`alone after ${(engine.soloMs / 1000).toFixed(1)} s`);
            if (engine.baselineMs !== null) parts.push(`${engine.baselineMs} ms per frame before zbar started`);
            if (name === 'zbar' && this.zbarBudget) parts.push(`backoff ${this.zbarBudget.backoff}x (max ${this.zbarBudget.maxBackoff}x)`);
            return parts.join(', ');
        };
        const band = zbar && zbar.region ? `, band ${zbar.region.w}x${zbar.region.h}${zbar.region.vertical ? ' upright' : ''}` : '';
        const loading = this.zbarPlanned && !zbar ? ' (zbar loading or unavailable)' : '';

        // What was read first: a pinned bar may cover the end of the panel in a screenshot
        const lines = [
            `engine: ${this.engine || 'loading'}${this.forcedEngine ? ` (forced ${this.forcedEngine})` : ''}${loading}${band}`,
            `accepted: ${this.stats.accepted ? `${this.stats.accepted.code} (${this.stats.accepted.count}x) by ${this.stats.acceptedBy}, first after ${(this.stats.accepted.afterMs / 1000).toFixed(1)} s` : '-'}`,
            `seen: ${this._seenText() || '-'}`,
            `frames: ${this.stats.frames}, decode ${avg} ms, running ${((Date.now() - this.startedAt) / 1000).toFixed(0)} s`,
            engineLine('native'),
            engineLine('zbar'),
            `device: ${this.deviceModel || '-'}`,
            `camera: ${this.videoTrack ? this.videoTrack.label : '-'} (${this.cameraPick || '-'})`,
            `cameras: ${(this.cameraLabels || []).join(' | ') || '-'}`,
            `video: ${video.videoWidth}x${video.videoHeight}, settings ${settings.width}x${settings.height} @${round(settings.frameRate)} fps, max ${caps.width ? caps.width.max : '-'}x${caps.height ? caps.height.max : '-'}`,
            `zoom: ${this.zoomCapabilities ? this.currentZoom : 'not supported'} (settings ${round(settings.zoom)}, range ${range(caps.zoom)})`,
            `focus: ${settings.focusMode || '-'} [${(caps.focusMode || []).join(', ')}], distance ${round(settings.focusDistance)} (range ${range(caps.focusDistance)})`,
            `last refocus: ${this.lastFocusKick ? `${this.lastFocusKick.reason} ${ago(this.lastFocusKick.at)}` : '-'}`,
            `torch: ${this.torchSupported ? (this.torchOn ? 'on' : 'off') : 'not supported'}`,
            `browser: ${navigator.userAgent}`,
        ];

        this.debugText.textContent = lines.filter(line => line !== null).join('\n');
    }

    _copyDebug(button) {
        const text = `${this.debugText.textContent}\npage: ${window.location.href}`;
        const done = (label) => {
            button.textContent = label;
            window.setTimeout(() => { button.textContent = 'Copy'; }, 2000);
        };

        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            navigator.clipboard.writeText(text).then(() => done('Copied'), () => this._selectDebug());
        } else {
            this._selectDebug();
        }
    }

    // No clipboard access: select the text, so the phone's own Copy works
    _selectDebug() {
        const range = document.createRange();
        range.selectNodeContents(this.debugText);
        const selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);
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

        if (window.isNativeApp && this.continuousValue && this.nativeAwaitingResume) {
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
