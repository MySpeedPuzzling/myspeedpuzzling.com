/**
 * Continuous scanning with a warm camera on the judge page (judge-speed J4,
 * docs/features/competitions-management/live-results.md "Scanning"): one camera for the page's scans, kept warm between
 * them, so the next tag after a Save is read without a new getUserMedia (no second permission question on iOS).
 *
 * States: cold → starting → scanning ⇄ parked → cold.
 * - scan(): warm = the <video> gets the stream back and reading starts at once; cold = the camera is asked for once (a
 *   second scan() while it starts joins the same request - never two getUserMedia).
 * - A code read is acted on once per scan(): the session parks itself before it hands the code on, a read still
 *   running is dropped. The tag the judge just saved (`ignore`) is ignored while it stays in view - until no frame
 *   showed it for OUT_OF_VIEW_MS; any other code acts at once. A frame with two codes that would act (two tags in
 *   view) acts on neither - the next frame with one does: never a guess between entries.
 * - park(): reading stops, the <video> is taken off the stream, the tracks stay live - released after IDLE_MS.
 * - release(reason): the tracks stopped → cold. The controller calls it when the page is hidden, left or frozen;
 *   the session itself after IDLE_MS without a read acted on (scanning or parked) and when the browser ends the track.
 *
 * Pure apart from the injected camera, reader, read loop and timers - tests/live-entry-scan-harness.mjs runs it under node.
 */

export const IDLE_MS = 90_000;
export const OUT_OF_VIEW_MS = 1_500;

/**
 * What to do with a code read: 'act', or 'ignore' while it is the remembered one (`memory` = {raw, seenAt}) and was in
 * view less than `outOfViewMs` ago - every sighting moves seenAt, so it acts again only after a gap with no frame of it.
 *
 * @returns {{decision: 'act'|'ignore', memory: {raw: string, seenAt: number}}}
 */
export function scanDecision(memory, raw, now, outOfViewMs = OUT_OF_VIEW_MS) {
    const decision = memory !== null && memory !== undefined && memory.raw === raw && now - memory.seenAt < outOfViewMs ? 'ignore' : 'act';

    return { decision, memory: { raw, seenAt: now } };
}

/**
 * Whether a Save goes straight back to the scanner: only an entry opened by a scan (`openedByScan` = {ref, raw}), the
 * saved one, and only while this phone's preference is not off.
 */
export function resumesAfterSave(openedByScan, savedRef, prefs) {
    return openedByScan !== null && openedByScan !== undefined && openedByScan.ref === savedRef && prefs?.scanAfterSave !== false;
}

export class ScanSession {
    /**
     * @param {object} options
     * @param {function(): Promise<{ok: boolean, camera?: object, reason?: string}>} options.openCamera official_results_scan.js openQrCamera()
     * @param {function(): Promise<object|null>} options.reader official_results_scan.js qrReader()
     * @param {function({video, reader, onCodes}): function(): void} options.loop official_results_scan.js detectLoop()
     */
    constructor({ openCamera, reader, loop, now = () => Date.now(), setTimer = (callback, ms) => setTimeout(callback, ms), clearTimer = (id) => clearTimeout(id), idleMs = IDLE_MS, outOfViewMs = OUT_OF_VIEW_MS }) {
        this.openCamera = openCamera;
        this.reader = reader;
        this.loop = loop;
        this.now = now;
        this.setTimer = setTimer;
        this.clearTimer = clearTimer;
        this.idleMs = idleMs;
        this.outOfViewMs = outOfViewMs;
        this.state = 'cold';
        this.camera = null;
        this.opening = null;
        // Bumped by release(): a camera that arrives for an older generation is stopped at once
        this.generation = 0;
        // The scan() being served - {video, onCode, onError}; null while parked or cold
        this.request = null;
        this.stopLoop = null;
        this.attached = null;
        this.idleTimer = null;
        this.memory = null;
        this.listeners = new Set();
    }

    /**
     * Shows the camera in `video` and reads until a code is acted on (onCode(raw), once) or park()/release().
     * onError('camera' | 'decoder') when it cannot.
     *
     * @returns {Promise<'scanning'|'camera'|'decoder'|'cancelled'>}
     */
    scan({ video, onCode, onError = () => {}, ignore = null }) {
        this.stopReading();
        const request = { video, onCode, onError };
        this.request = request;
        this.memory = ignore === null || ignore === undefined ? null : { raw: String(ignore), seenAt: this.now() };

        if (this.camera !== null && this.camera.alive()) {
            return this.startReading(request);
        }

        if (this.camera !== null) {
            // Ended without its event reaching us (a background tab on iOS) - a fresh one
            this.dropCamera();
        }

        if (this.opening === null) {
            this.opening = this.open();
        }

        return this.opening.then((outcome) => {
            if (this.request !== request) {
                return 'cancelled';
            }

            if (outcome !== 'ok') {
                this.request = null;
                request.onError('camera');

                return 'camera';
            }

            return this.startReading(request);
        });
    }

    /**
     * Reading stops, the camera stays warm (the idle timer runs from the last use). Nothing to do when nothing was read.
     */
    park() {
        const active = this.request !== null;
        this.request = null;
        this.stopReading();

        if (this.camera !== null) {
            this.setState('parked');

            if (active) {
                this.startIdle();
            }
        }
    }

    release(reason = 'released') {
        this.generation += 1;
        this.opening = null;
        this.request = null;
        this.memory = null;
        this.stopReading();
        this.dropCamera();
        this.setState('cold', reason);
    }

    /**
     * cb(state, reason) on every change of state - the Scan button's "camera ready" dot, the overlay's reaction to a
     * camera that went off.
     *
     * @returns {function(): void} unsubscribe
     */
    subscribe(callback) {
        this.listeners.add(callback);

        return () => this.listeners.delete(callback);
    }

    warm() {
        return this.state === 'parked' || this.state === 'scanning';
    }

    // ---- inside ----

    open() {
        const generation = this.generation;
        let asked;

        this.setState('starting');

        try {
            // Called now, inside the tap - iOS asks for the camera only from a user's gesture
            asked = Promise.resolve(this.openCamera());
        } catch (e) {
            asked = Promise.resolve({ ok: false, reason: 'camera' });
        }

        return asked.catch(() => ({ ok: false, reason: 'camera' })).then((result) => {
            if (generation !== this.generation) {
                // Released while it started (page hidden, left)
                if (result?.ok) {
                    result.camera.release();
                }

                return 'cancelled';
            }

            this.opening = null;

            if (!result?.ok) {
                this.setState('cold', 'camera');

                return 'camera';
            }

            const camera = result.camera;
            this.camera = camera;
            camera.onEnded(() => {
                if (this.camera === camera) {
                    this.release('ended');
                }
            });
            this.setState('parked');
            this.startIdle();

            return 'ok';
        });
    }

    async startReading(request) {
        const qr = await this.reader();

        if (this.request !== request) {
            return 'cancelled';
        }

        if (qr === null) {
            this.release('decoder');
            request.onError('decoder');

            return 'decoder';
        }

        if (this.camera === null || !this.camera.alive()) {
            this.request = null;
            this.dropCamera();
            // (Not 'ended': this request answers itself with the error - the page shows Try again)
            this.setState('cold', 'camera');
            request.onError('camera');

            return 'camera';
        }

        this.camera.attach(request.video);
        this.attached = request.video;
        this.setState('scanning');
        // Scanning with nothing read counts as idle too - a phone put down with the scanner open turns its camera off
        this.startIdle();
        this.stopLoop = this.loop({ video: request.video, reader: qr, onCodes: (raws) => this.seen(request, raws) });

        return 'scanning';
    }

    seen(request, raws) {
        if (this.request !== request || this.state !== 'scanning') {
            return;
        }

        const now = this.now();
        const acting = [];

        for (const raw of new Set(raws)) {
            const { decision, memory } = scanDecision(this.memory, raw, now, this.outOfViewMs);

            if (decision === 'ignore') {
                // Only a sighting of the ignored tag moves its memory
                this.memory = memory;
            } else {
                acting.push(raw);
            }
        }

        // Nothing new, or two tags that would act in one frame - wait for a frame with exactly one
        if (acting.length !== 1) {
            return;
        }

        // Once per scan(): parked before the code is handed on - a read after it is never acted on
        this.park();
        request.onCode(acting[0]);
    }

    stopReading() {
        if (this.stopLoop !== null) {
            this.stopLoop();
            this.stopLoop = null;
        }

        if (this.attached !== null) {
            if (this.camera !== null) {
                this.camera.detach(this.attached);
            } else {
                this.attached.srcObject = null;
            }

            this.attached = null;
        }
    }

    dropCamera() {
        this.clearIdle();

        if (this.camera !== null) {
            const camera = this.camera;
            this.camera = null;
            camera.release();
        }
    }

    startIdle() {
        this.clearIdle();
        this.idleTimer = this.setTimer(() => {
            this.idleTimer = null;
            this.release('idle');
        }, this.idleMs);
    }

    clearIdle() {
        if (this.idleTimer !== null) {
            this.clearTimer(this.idleTimer);
            this.idleTimer = null;
        }
    }

    setState(state, reason = null) {
        if (this.state === state) {
            return;
        }

        this.state = state;
        this.listeners.forEach((callback) => {
            try {
                callback(state, reason);
            } catch (e) {
                // A listener's problem is not the camera's
            }
        });
    }
}
