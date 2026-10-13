/**
 * The participant phone's camera (docs/features/online-competitions/README.md §5.5 "Browser realities"): open it, keep
 * it, open it again - the evidence photos and the finish bursts come from it.
 *
 * - Front or back, the choice remembered on the device (STORAGE_FACING). The front camera is the default: the screen
 *   then faces the puzzler, who sees the clock and their own framing.
 * - 1080p asked as `ideal` (no browser caps it; the real size is read back - settings()), 30 fps.
 * - The back camera on Android: Chrome opens the highest-index back lens for facingMode - on phones with several back
 *   lenses often the ultra-wide without autofocus - so the main one ("camera 0, facing back") is picked by its label
 *   and remembered under the barcode scanner's key (STORAGE_BACK_LENS, its exact meaning: a deviceId, or 'facing' =
 *   the facingMode choice is right on this phone) - one lens choice for the whole site.
 * - The track's `mute` / `unmute` / `ended` go to onTrackEvent: iOS mutes the camera whenever the page is hidden,
 *   Android releases it when the screen turns off - the page logs them (camera.lost / camera.back) and opens it again.
 * - open() again while one is opening: the later call wins, the earlier stream is stopped as soon as it arrives.
 *
 * No DOM: navigator.mediaDevices, the storage and the device's OS are injected - pinned by
 * tests/online-devices-harness.mjs. The page attaches `stream` to its <video>. The OS comes from assets/platform.js
 * (os()), the one reader of the device's platform (docs/features/navigation README §4.13) - like the
 * barcode scanner's same re-pick.
 */

import { os } from '../platform.js';

export const STORAGE_FACING = 'msp-compete-facing';
// Shared with assets/controllers/barcode_scanner_controller.js (STORAGE_CAMERA there) - same values, same meaning
export const STORAGE_BACK_LENS = 'msp-scan-camera';
export const FACING_FRONT = 'user';
export const FACING_BACK = 'environment';
export const DEFAULT_FACING = FACING_FRONT;
export const VIDEO_CONSTRAINTS = { width: { ideal: 1920 }, height: { ideal: 1080 }, frameRate: { ideal: 30 } };

/**
 * What a failed getUserMedia means for the participant.
 *
 * @returns {'denied'|'unavailable'|'busy'|'error'}
 */
export function cameraErrorStatus(error) {
    switch (error?.name) {
        case 'NotAllowedError':
        case 'SecurityError':
        case 'PermissionDeniedError':
            return 'denied';
        case 'NotFoundError':
        case 'DevicesNotFoundError':
        case 'OverconstrainedError':
        case 'ConstraintNotSatisfiedError':
            return 'unavailable';
        case 'NotReadableError':
        case 'TrackStartError':
        case 'AbortError':
            return 'busy';
        default:
            return 'error';
    }
}

/**
 * Android names its cameras "camera 0, facing back" (Chrome 139+) or "camera2 0, facing back"; 0 is the main lens.
 * Null: keep the camera that is open (it is the main one, or no label says which is).
 */
export function mainBackCamera(devices, currentDeviceId) {
    const main = (devices ?? []).find((device) => device?.kind === 'videoinput' && /^camera2? 0\b.*facing back/i.test(device.label ?? ''));

    return main && main.deviceId !== currentDeviceId ? main : null;
}

/**
 * A lens that focuses by itself - or one that does not say (most do not list focus modes at all).
 */
function focusesContinuously(track) {
    try {
        const modes = typeof track?.getCapabilities === 'function' ? track.getCapabilities()?.focusMode : undefined;

        return !Array.isArray(modes) || modes.includes('continuous');
    } catch (e) {
        return true;
    }
}

function readStorage(storage, key) {
    try {
        return storage?.getItem(key) ?? null;
    } catch (e) {
        return null;
    }
}

function writeStorage(storage, key, value) {
    try {
        if (value === null) {
            storage?.removeItem(key);
        } else {
            storage?.setItem(key, value);
        }
    } catch (e) {
        // A private window - chosen again next time
    }
}

function stopStream(stream) {
    try {
        stream?.getTracks?.().forEach((track) => track.stop());
    } catch (e) {
        // Already gone
    }
}

export class Camera {
    /**
     * @param {object} options
     * @param {MediaDevices|null} [options.mediaDevices]
     * @param {Storage|null} [options.storage]
     * @param {'ios'|'android'|'other'} [options.deviceOs]                os() of assets/platform.js
     * @param {function(Camera): void} [options.onChange]                  status / stream changed
     * @param {function({type: 'mute'|'unmute'|'ended'}): void} [options.onTrackEvent]
     */
    constructor({
        mediaDevices = globalThis.navigator?.mediaDevices ?? null,
        storage = null,
        deviceOs = os(),
        onChange = () => {},
        onTrackEvent = () => {},
    } = {}) {
        this.mediaDevices = mediaDevices;
        this.storage = storage;
        this.deviceOs = deviceOs;
        this.onChange = onChange;
        this.onTrackEvent = onTrackEvent;

        /** @type {'idle'|'opening'|'live'|'muted'|'ended'|'denied'|'unavailable'|'busy'|'error'} */
        this.status = 'idle';
        this.error = null;
        this.stream = null;
        this.track = null;
        // How the lens was chosen: 'front', 'facingMode', 'remembered main lens', 'main lens'
        this.pick = null;
        this.session = 0;
        this.listeners = null;

        const remembered = readStorage(storage, STORAGE_FACING);
        this.facing = remembered === FACING_BACK || remembered === FACING_FRONT ? remembered : DEFAULT_FACING;
    }

    isSupported() {
        return typeof this.mediaDevices?.getUserMedia === 'function';
    }

    /**
     * The real size and lens of the open track (what the page reports) - empty while none is open.
     *
     * @returns {{width?: number, height?: number, frameRate?: number, deviceId?: string, facingMode?: string}}
     */
    settings() {
        try {
            return this.track && typeof this.track.getSettings === 'function' ? (this.track.getSettings() ?? {}) : {};
        } catch (e) {
            return {};
        }
    }

    /**
     * Opens the camera (the remembered facing, or the one given - then remembered). Resolves with the status.
     *
     * @param {{facing?: string}} [options]
     * @returns {Promise<string>}
     */
    async open({ facing = null } = {}) {
        if (facing === FACING_FRONT || facing === FACING_BACK) {
            this.facing = facing;
            writeStorage(this.storage, STORAGE_FACING, facing);
        }

        const session = ++this.session;
        this.release();

        if (!this.isSupported()) {
            this.setStatus('unavailable', null);

            return this.status;
        }

        this.setStatus('opening', null);

        let opened;

        try {
            opened = await this.openStream();
        } catch (error) {
            if (session === this.session) {
                this.setStatus(cameraErrorStatus(error), error);
            }

            return this.status;
        }

        // Stopped, or opened again, meanwhile
        if (session !== this.session) {
            stopStream(opened.stream);

            return this.status;
        }

        this.stream = opened.stream;
        this.pick = opened.pick;
        this.track = opened.stream.getVideoTracks?.()[0] ?? null;

        if (this.track === null) {
            stopStream(opened.stream);
            this.stream = null;
            this.setStatus('unavailable', null);

            return this.status;
        }

        this.listen(this.track);
        this.setStatus(this.track.readyState === 'ended' ? 'ended' : (this.track.muted ? 'muted' : 'live'), null);

        return this.status;
    }

    /**
     * The other camera (front ↔ back), remembered.
     */
    switchFacing() {
        return this.open({ facing: this.facing === FACING_FRONT ? FACING_BACK : FACING_FRONT });
    }

    reopen() {
        return this.open();
    }

    /**
     * Turns the camera off; an open() still under way is dropped when it arrives.
     */
    stop() {
        this.session += 1;
        this.release();
        this.setStatus('idle', null);
    }

    // ---- internals

    release() {
        if (this.track !== null && this.listeners !== null) {
            for (const [type, listener] of Object.entries(this.listeners)) {
                try {
                    this.track.removeEventListener(type, listener);
                } catch (e) {
                    // Gone with its stream
                }
            }
        }

        this.listeners = null;
        stopStream(this.stream);
        this.stream = null;
        this.track = null;
    }

    listen(track) {
        this.listeners = {
            mute: () => this.trackEvent('mute'),
            unmute: () => this.trackEvent('unmute'),
            ended: () => this.trackEvent('ended'),
        };

        for (const [type, listener] of Object.entries(this.listeners)) {
            try {
                track.addEventListener(type, listener);
            } catch (e) {
                // A track without events - the page's own checks still see it end
            }
        }
    }

    trackEvent(type) {
        if (this.status === 'idle') {
            return;
        }

        if (type === 'mute') {
            this.setStatus('muted', null);
        } else if (type === 'unmute') {
            this.setStatus('live', null);
        } else if (type === 'ended') {
            this.setStatus('ended', null);
        }

        try {
            this.onTrackEvent({ type });
        } catch (e) {
            // The page's listener must never break the camera
        }
    }

    setStatus(status, error) {
        this.status = status;
        this.error = error;

        try {
            this.onChange(this);
        } catch (e) {
            // The page's listener must never break the camera
        }
    }

    constraints(camera) {
        return { audio: false, video: { ...camera, ...VIDEO_CONSTRAINTS } };
    }

    /**
     * @returns {Promise<{stream: MediaStream, pick: string}>}
     */
    async openStream() {
        const media = this.mediaDevices;

        if (this.facing === FACING_FRONT) {
            return { stream: await media.getUserMedia(this.constraints({ facingMode: { ideal: FACING_FRONT } })), pick: 'front' };
        }

        const remembered = readStorage(this.storage, STORAGE_BACK_LENS);

        if (remembered && remembered !== 'facing') {
            try {
                return { stream: await media.getUserMedia(this.constraints({ deviceId: { exact: remembered } })), pick: 'remembered main lens' };
            } catch (e) {
                if (cameraErrorStatus(e) === 'denied') {
                    throw e;
                }

                // Gone (cleared site data rotates the ids, another browser profile): picked again
                writeStorage(this.storage, STORAGE_BACK_LENS, null);
            }
        }

        const stream = await media.getUserMedia(this.constraints({ facingMode: { ideal: FACING_BACK } }));

        // iPhones are fine: Safari picks the virtual camera that switches lenses by itself
        if (remembered === 'facing' || this.deviceOs !== 'android') {
            return { stream, pick: 'facingMode' };
        }

        let main = null;

        try {
            const current = stream.getVideoTracks?.()[0];
            const currentId = current && typeof current.getSettings === 'function' ? current.getSettings()?.deviceId : null;
            main = mainBackCamera(await media.enumerateDevices(), currentId);
        } catch (e) {
            main = null;
        }

        if (main === null) {
            writeStorage(this.storage, STORAGE_BACK_LENS, 'facing');

            return { stream, pick: 'facingMode' };
        }

        // Several phones cannot hold two cameras open
        stopStream(stream);

        try {
            const mainStream = await media.getUserMedia(this.constraints({ deviceId: { exact: main.deviceId } }));

            // The scanner's rule for the shared key: the main lens only when it focuses by itself
            if (focusesContinuously(mainStream.getVideoTracks?.()[0])) {
                writeStorage(this.storage, STORAGE_BACK_LENS, main.deviceId);

                return { stream: mainStream, pick: 'main lens' };
            }

            stopStream(mainStream);
        } catch (e) {
            if (cameraErrorStatus(e) === 'denied') {
                throw e;
            }
        }

        writeStorage(this.storage, STORAGE_BACK_LENS, 'facing');

        return { stream: await media.getUserMedia(this.constraints({ facingMode: { ideal: FACING_BACK } })), pick: 'facingMode' };
    }
}
