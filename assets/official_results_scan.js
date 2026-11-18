/**
 * Reading a competition tag's QR in the live result entry (docs/features/competitions-management/live-results.md): the
 * name tag, and the line-up, table and entry tags (competition toolkit C8).
 * Separate from the EAN scanner (controllers/barcode_scanner_controller.js), which stays as it is: the browser's own
 * BarcodeDetector where it reads QR codes, else zbar-wasm + its polyfill - from our own server first (the EAN scanner's
 * order and files, README §5.9 of the barcode scanner; the service worker keeps them on a referee's device, toolkit C6,
 * so an iPhone scans after an offline reload), jsdelivr only when ours does not work.
 * The building blocks of a scan (judge-speed J4): openQrCamera() / cameraOf(), the page's one qrReader(), detectLoop() -
 * live_entry_scan_session.js keeps the camera warm between scans with them.
 */

import { parseLiveEntryRoute } from './live_entry_route.js';

// Each source: the decoder's .wasm (fetched first - a source whose .wasm does not arrive is skipped before any of its
// scripts runs), then zbar-wasm and the polyfill that depends on it
export const ZBAR_SOURCES = [
    {
        name: 'self',
        wasm: '/barcode-scanner/zbar-wasm-0.9.15/zbar.wasm',
        scripts: [
            { src: '/barcode-scanner/zbar-wasm-0.9.15/zbar-wasm.js', global: 'zbarWasm' },
            { src: '/barcode-scanner/zbar-wasm-0.9.15/barcode-detector-polyfill.js', global: 'barcodeDetectorPolyfill' },
        ],
    },
    {
        name: 'cdn',
        wasm: null,
        scripts: [
            { src: 'https://cdn.jsdelivr.net/npm/@undecaf/zbar-wasm@0.9.15/dist/index.js', global: 'zbarWasm' },
            { src: 'https://cdn.jsdelivr.net/npm/@undecaf/barcode-detector-polyfill@0.9.21/dist/index.js', global: 'barcodeDetectorPolyfill' },
        ],
    },
];
// A source that has not answered by then is given up for the next one
const ZBAR_SOURCE_TIMEOUT_MS = 8000;

const NAME_TAG_PATH = /^\/(?:[a-z]{2}\/)?live\/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\/p\/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\/?$/i;

/**
 * A competition tag's URL (R-review-and-plan.md §1.3) - the printed name tag (route live_results_scan, read exactly as
 * it always was) or one of the frozen tag paths `/live/{competitionId}/g|t|e/{id}` (live_entry_route.js, with or
 * without a locale): `{competitionId, kind: 'participant'|'lineup'|'table'|'entry', id}` (a table's id is its number),
 * null for anything else. Any host - a tag printed on production still reads on another installation's page.
 *
 * @returns {{competitionId: string, kind: string, id: string|number}|null}
 */
export function parseCompetitionTagUrl(text) {
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

    if (match !== null) {
        return { competitionId: match[1].toLowerCase(), kind: 'participant', id: match[2].toLowerCase() };
    }

    const route = parseLiveEntryRoute(url.pathname);

    return route !== null && ['lineup', 'table', 'entry'].includes(route.kind)
        ? { competitionId: route.competitionId, kind: route.kind, id: route.id }
        : null;
}

/**
 * The ids in a name tag's URL (route live_results_scan), or null for anything else.
 *
 * @returns {{competitionId: string, participantId: string}|null}
 */
export function parseNameTagUrl(text) {
    const tag = parseCompetitionTagUrl(text);

    return tag !== null && tag.kind === 'participant' ? { competitionId: tag.competitionId, participantId: tag.id } : null;
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
        script.onerror = (error) => {
            // A failed tag would make the next try "still loading"
            script.remove();
            reject(error);
        };
        document.head.appendChild(script);
    });
}

/**
 * Whether this browser reads QR codes itself (BarcodeDetector with `qr_code`) - else the page needs zbar.
 */
export async function nativeReadsQr() {
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

/**
 * Our server's copy first, jsdelivr second. Two copies of zbar in one page share its globals (the EAN scanner found the
 * second one's detect() never answering), so once a source's scripts ran no other source is tried.
 */
async function loadZbar() {
    for (const source of ZBAR_SOURCES) {
        let timer = null;
        const timeout = new Promise((resolve, reject) => {
            timer = setTimeout(() => reject(new Error('timed out')), ZBAR_SOURCE_TIMEOUT_MS);
        });

        try {
            await Promise.race([timeout, (async () => {
                if (source.wasm) {
                    // The whole file, so zbar's own fetch of it is answered from the cache
                    const response = await fetch(source.wasm);

                    if (!response.ok) {
                        throw new Error(`${source.wasm}: HTTP ${response.status}`);
                    }

                    await response.arrayBuffer();
                }

                for (const script of source.scripts) {
                    await loadScript(script);
                }
            })()]);

            return;
        } catch (e) {
            if (typeof zbarWasm !== 'undefined') {
                return;
            }
        } finally {
            clearTimeout(timer);
        }
    }
}

let reader = null;
let readerLoading = null;

async function qrDetectorClass() {
    if (detectorClass === undefined) {
        if (await nativeReadsQr()) {
            detectorClass = window.BarcodeDetector;
        } else {
            await loadZbar();

            detectorClass = typeof barcodeDetectorPolyfill !== 'undefined' && barcodeDetectorPolyfill.BarcodeDetectorPolyfill
                ? barcodeDetectorPolyfill.BarcodeDetectorPolyfill
                : null;
        }
    }

    if (detectorClass === null) {
        // Asked again on the next scan (the connection may be back) - unless a decoder's scripts already ran
        if (typeof zbarWasm === 'undefined') {
            detectorClass = undefined;
        }

        return null;
    }

    return detectorClass;
}

/**
 * The page's one QR reader (judge-speed J4): the browser's own BarcodeDetector where it reads `qr_code`, else the zbar
 * polyfill - built once and kept for every scan of the page; null when no decoder could be loaded (asked again next
 * time). Calls while it loads share the one load.
 *
 * @returns {Promise<{detect: function(*): Promise<Array<{rawValue: string}>>}|null>}
 */
export function qrReader() {
    if (reader !== null) {
        return Promise.resolve(reader);
    }

    if (readerLoading === null) {
        readerLoading = qrDetectorClass()
            .then((Detector) => {
                if (Detector !== null && reader === null) {
                    reader = new Detector({ formats: ['qr_code'] });
                }

                return Detector === null ? null : reader;
            })
            .catch(() => null)
            .finally(() => {
                readerLoading = null;
            });
    }

    return readerLoading;
}

export const QR_CAMERA = {
    audio: false,
    video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } },
};

/**
 * A started camera (judge-speed J4): shown in a <video> while scanning, taken off it while parked (the tracks stay
 * live - no second permission question), stopped on release. `ended` is the browser ending the capture itself (iOS
 * on a call, another app, Siri) - stopping the tracks ourselves does not fire it.
 */
export function cameraOf(stream) {
    const videoTracks = () => (typeof stream.getVideoTracks === 'function' ? stream.getVideoTracks() : stream.getTracks());
    let released = false;

    return {
        stream,
        attach(video) {
            video.setAttribute('playsinline', '');
            video.muted = true;

            if (video.srcObject !== stream) {
                video.srcObject = stream;
            }

            // Always again: Safari stops a <video> that was hidden or taken off its stream
            const playing = video.play();

            if (playing && typeof playing.catch === 'function') {
                playing.catch(() => {});
            }
        },
        detach(video) {
            try {
                video.pause();
            } catch (e) {
                // Not playing
            }

            video.srcObject = null;
        },
        alive() {
            const tracks = videoTracks();

            return !released && tracks.length > 0 && tracks.every((track) => track.readyState === 'live');
        },
        release() {
            released = true;
            stream.getTracks().forEach((track) => {
                try {
                    track.stop();
                } catch (e) {
                    // Already stopped
                }
            });
        },
        onEnded(callback) {
            videoTracks().forEach((track) => track.addEventListener('ended', () => callback(), { once: true }));
        },
    };
}

/**
 * Asks for the back camera: `{ok: true, camera}` | `{ok: false, reason: 'camera'}` (no camera, permission refused).
 * getUserMedia is called before anything is awaited - inside the tap that asked for it (iOS asks only then).
 */
export async function openQrCamera({ mediaDevices }) {
    if (!mediaDevices || typeof mediaDevices.getUserMedia !== 'function') {
        return { ok: false, reason: 'camera' };
    }

    try {
        return { ok: true, camera: cameraOf(await mediaDevices.getUserMedia(QR_CAMERA)) };
    } catch (e) {
        return { ok: false, reason: 'camera' };
    }
}

// About ten reads a second - enough for a QR held still, light on a phone's battery
function nextRead(read) {
    setTimeout(() => requestAnimationFrame(read), 100);
}

/**
 * Reads QR codes from `video` until stop(): onCode(rawValue) for every frame with a code - what to do with it is the
 * caller's (live_entry_scan_session.js); never stops the camera. A read still running when stop() is called is
 * dropped, never handed on.
 *
 * @returns {function(): void} stop
 */
export function detectLoop({ video, reader: qr, onCode, schedule = nextRead }) {
    let stopped = false;

    const read = async () => {
        if (stopped) {
            return;
        }

        if (video.readyState >= 2) {
            let raw = null;

            try {
                const codes = await qr.detect(video);
                const code = (codes ?? []).find((candidate) => typeof candidate?.rawValue === 'string' && candidate.rawValue !== '');
                raw = code ? code.rawValue : null;
            } catch (e) {
                // A frame that could not be read - the next one may
            }

            if (stopped) {
                return;
            }

            if (raw !== null) {
                onCode(raw);
            }
        }

        if (!stopped) {
            schedule(read);
        }
    };

    schedule(read);

    return () => {
        stopped = true;
    };
}
