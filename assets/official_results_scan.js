/**
 * Reading a name tag's QR in the live result entry (docs/features/competitions-management/live-results.md).
 * Separate from the EAN scanner (controllers/barcode_scanner_controller.js), which stays as it is: the browser's own
 * BarcodeDetector where it reads QR codes, else the same zbar-wasm build and polyfill the EAN scanner loads (the
 * same script URLs, so a page never loads them twice).
 */

const ZBAR_SCRIPTS = [
    { src: 'https://cdn.jsdelivr.net/npm/@undecaf/zbar-wasm@0.9.15/dist/index.js', global: 'zbarWasm' },
    { src: 'https://cdn.jsdelivr.net/npm/@undecaf/barcode-detector-polyfill@0.9.21/dist/index.js', global: 'barcodeDetectorPolyfill' },
];

const NAME_TAG_PATH = /^\/(?:[a-z]{2}\/)?live\/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\/p\/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\/?$/i;

/**
 * The ids in a name tag's URL (route live_results_scan), or null for anything else.
 *
 * @returns {{competitionId: string, participantId: string}|null}
 */
export function parseNameTagUrl(text) {
    let url;

    try {
        url = new URL(String(text ?? '').trim());
    } catch (e) {
        return null;
    }

    if (url.protocol !== 'https:' && url.protocol !== 'http:') {
        return null;
    }

    const match = url.pathname.match(NAME_TAG_PATH);

    if (match === null) {
        return null;
    }

    return { competitionId: match[1].toLowerCase(), participantId: match[2].toLowerCase() };
}

function loadScript({ src, global }) {
    return new Promise((resolve, reject) => {
        if (window[global] !== undefined) {
            resolve();

            return;
        }

        const existing = document.querySelector(`script[src="${src}"]`);

        if (existing !== null) {
            // Still loading - the EAN scanner asked first
            existing.addEventListener('load', () => resolve(), { once: true });
            existing.addEventListener('error', reject, { once: true });

            return;
        }

        const script = document.createElement('script');
        script.src = src;
        script.onload = () => resolve();
        script.onerror = reject;
        document.head.appendChild(script);
    });
}

async function nativeReadsQr() {
    if (!window.BarcodeDetector || typeof window.BarcodeDetector.getSupportedFormats !== 'function') {
        return false;
    }

    try {
        return (await window.BarcodeDetector.getSupportedFormats()).indexOf('qr_code') !== -1;
    } catch (e) {
        return false;
    }
}

let detectorClass;

async function qrDetector() {
    if (detectorClass === undefined) {
        if (await nativeReadsQr()) {
            detectorClass = window.BarcodeDetector;
        } else {
            try {
                for (const script of ZBAR_SCRIPTS) {
                    await loadScript(script);
                }
            } catch (e) {
                // Reported below as "no decoder"
            }

            detectorClass = typeof barcodeDetectorPolyfill !== 'undefined' && barcodeDetectorPolyfill.BarcodeDetectorPolyfill
                ? barcodeDetectorPolyfill.BarcodeDetectorPolyfill
                : null;
        }
    }

    return detectorClass === null ? null : new detectorClass({ formats: ['qr_code'] });
}

/**
 * Starts the camera into `video` and reads QR codes until one is found (onResult(rawValue)) or stop() is called.
 * onError(reason): 'camera' (no camera / permission refused) | 'decoder' (no QR reader could be loaded).
 *
 * @returns {Promise<function(): void>} stop
 */
export async function startQrScan({ video, onResult, onError }) {
    let stopped = false;
    let stream = null;

    const stop = () => {
        stopped = true;

        if (stream !== null) {
            stream.getTracks().forEach((track) => track.stop());
            stream = null;
        }

        video.srcObject = null;
    };

    if (!navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
        onError('camera');

        return stop;
    }

    try {
        stream = await navigator.mediaDevices.getUserMedia({
            audio: false,
            video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
        });
    } catch (e) {
        onError('camera');

        return stop;
    }

    if (stopped) {
        stop();

        return stop;
    }

    video.setAttribute('playsinline', '');
    video.muted = true;
    video.srcObject = stream;
    video.play().catch(() => {});

    const detector = await qrDetector();

    if (detector === null) {
        stop();
        onError('decoder');

        return stop;
    }

    let busy = false;

    const tick = async () => {
        if (stopped) {
            return;
        }

        if (!busy && video.readyState >= 2) {
            busy = true;

            try {
                const codes = await detector.detect(video);
                const code = codes.find((candidate) => typeof candidate.rawValue === 'string' && candidate.rawValue !== '');

                if (code && !stopped) {
                    stop();
                    onResult(code.rawValue);

                    return;
                }
            } catch (e) {
                // A frame that could not be read - the next one may
            }

            busy = false;
        }

        // About ten reads a second - enough for a QR held still, light on a phone's battery
        setTimeout(() => requestAnimationFrame(tick), 100);
    };

    requestAnimationFrame(tick);

    return stop;
}
