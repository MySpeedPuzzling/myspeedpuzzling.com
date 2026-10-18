/**
 * Background photo uploads (docs/features/photo-uploads/README.md).
 *
 * A photo is uploaded the moment it is picked - while the player is still filling in the form - straight to object
 * storage through a presigned PUT (PhotoUploadStartController → DirectPhotoUpload). No byte goes through PHP, so a slow
 * phone upload never meets the edge's request timeout (Traefik cut every upload at 60 s, 2026-10-14) and never holds a
 * PHP worker. The form's submit then carries only the token (`photo_stash[<field>]`), a few bytes.
 *
 * - A dropped or stalled upload is tried again by itself (backoff, at once when the browser says it is back online);
 *   the same token is signed again, so a retry never leaves a second photo behind.
 * - Whatever cannot work this way - the server refuses to start it (too large, unknown type, signed out, storage down),
 *   object storage refuses the signed upload, or it fails instantly twice although the page reached us a moment ago
 *   (a store this browser cannot reach) - ends as `failed`: the photo stays in its file input and goes with the form,
 *   exactly as before this existed. Nothing is ever worse than the plain form.
 *
 * Shared by the add/edit-time form (submit_prevention_controller.js) and multiscan quick-add (multiscan_controller.js).
 */

const BACKOFF_MS = [1000, 3000, 8000, 15000, 30000];
const OFFLINE_WAIT_MS = 60000;
// No progress for this long = the connection is gone without saying so - start again
const STALL_MS = 30000;
// A failure this quick, before a single byte went out, while the page itself just reached the server
const INSTANT_FAILURE_MS = 1500;
const INSTANT_RETRY_MS = 300;
const RESIGN_MARGIN_MS = 60000;
const DEFAULT_MAX_ATTEMPTS = 8;

const TYPES_BY_EXTENSION = {
    jpg: 'image/jpeg',
    jpeg: 'image/jpeg',
    png: 'image/png',
    gif: 'image/gif',
    webp: 'image/webp',
    heic: 'image/heic',
    heif: 'image/heif',
    avif: 'image/avif',
};

/**
 * Some phone browsers give a HEIC no type at all - the extension tells it then (the server checks the bytes anyway)
 */
export function photoType(file) {
    if (file.type) {
        return file.type;
    }

    const extension = (file.name.split('.').pop() || '').toLowerCase();

    return TYPES_BY_EXTENSION[extension] || 'application/octet-stream';
}

/**
 * One line per report on the photo_upload log channel (PhotoUploadReportController) - never throws, never waits
 */
export function reportPhotoUpload(url, payload) {
    if (!url) {
        return;
    }

    try {
        const connection = navigator.connection || null;
        const body = JSON.stringify({
            ...payload,
            page: window.location.pathname,
            online: navigator.onLine,
            effectiveType: connection ? connection.effectiveType : undefined,
            downlink: connection ? connection.downlink : undefined,
        });

        if (navigator.sendBeacon && navigator.sendBeacon(url, new Blob([body], { type: 'application/json' }))) {
            return;
        }

        fetch(url, { method: 'POST', body, headers: { 'Content-Type': 'application/json' }, keepalive: true }).catch(() => {});
    } catch (error) {
        // Telemetry must never get in the way
    }
}

export class PhotoUpload {
    /**
     * @param {File} file
     * @param {{startUrl: string, csrfToken: string, reportUrl?: string, field?: string, replaces?: string|null,
     *          maxAttempts?: number, onChange?: function(PhotoUpload): void}} options
     */
    constructor(file, options) {
        this.file = file;
        this.options = options;
        this.type = photoType(file);
        this.state = 'uploading';
        this.reason = null;
        this.status = null;
        this.retryable = false;
        this.token = null;
        this.signed = null;
        this.replaces = options.replaces || null;
        this.loaded = 0;
        this.total = file.size;
        this.attempts = 0;
        this.maxAttempts = options.maxAttempts || DEFAULT_MAX_ATTEMPTS;
        this.storeRefusals = 0;
        this.instantFailures = 0;
        this.waitingUntil = null;
        this.startedAt = Date.now();
        this.aborted = false;
        this.xhr = null;
        this.wake = null;

        this.done = new Promise((resolve) => {
            this.resolveDone = resolve;
        });

        this.run();
    }

    /** 0-100, what went out of the current attempt */
    get percent() {
        if (this.state === 'done') {
            return 100;
        }

        return this.total > 0 ? Math.min(99, Math.floor((this.loaded / this.total) * 100)) : 0;
    }

    get elapsedMs() {
        return Date.now() - this.startedAt;
    }

    /** Another photo was picked, the form is gone - stop without a word */
    abort() {
        this.aborted = true;

        if (this.xhr) {
            this.xhr.abort();
        }

        if (this.wake) {
            this.wake();
        }

        if (this.state === 'uploading') {
            this.state = 'aborted';
            this.resolveDone(this);
        }
    }

    /**
     * The player is saving and this upload gave up on a bad connection: a few more tries before the photo goes with the
     * form (the form would need the same connection). Waits in a backoff end at once.
     */
    resumeForSubmit(extraAttempts = 3) {
        if (this.aborted) {
            return;
        }

        if (this.state === 'uploading') {
            if (this.wake) {
                this.wake();
            }
            return;
        }

        if (this.state !== 'failed' || !this.retryable) {
            return;
        }

        this.state = 'uploading';
        this.reason = null;
        this.maxAttempts = this.attempts + extraAttempts;
        this.done = new Promise((resolve) => {
            this.resolveDone = resolve;
        });
        this.changed();
        this.run();
    }

    changed() {
        if (this.options.onChange) {
            try {
                this.options.onChange(this);
            } catch (error) {
                // The status line is not worth breaking the upload for
            }
        }
    }

    async run() {
        while (!this.aborted) {
            this.attempts += 1;
            this.loaded = 0;
            this.changed();

            const outcome = await this.attempt();

            if (this.aborted) {
                return;
            }

            if (outcome.ok) {
                this.finish('done');
                return;
            }

            this.reason = outcome.reason;
            this.status = outcome.status ?? null;

            if (outcome.fatal) {
                this.finish('failed', false);
                return;
            }

            if (outcome.instant && navigator.onLine !== false) {
                this.instantFailures += 1;

                if (this.instantFailures >= 2) {
                    this.reason = 'unreachable';
                    this.finish('failed', false);
                    return;
                }
            }

            if (this.attempts >= this.maxAttempts) {
                this.finish('failed', true);
                return;
            }

            const backoff = BACKOFF_MS[Math.min(this.attempts - 1, BACKOFF_MS.length - 1)];
            // An instant failure is asked once more at once - an unreachable store then goes with the form within a
            // second, without a "connection dropped" line flashing up
            const instant = outcome.instant && navigator.onLine !== false;
            await this.wait(navigator.onLine === false ? OFFLINE_WAIT_MS : (instant ? INSTANT_RETRY_MS : backoff));
        }
    }

    finish(state, retryable = false) {
        this.state = state;
        this.retryable = retryable;
        this.waitingUntil = null;
        this.changed();

        reportPhotoUpload(this.options.reportUrl, {
            event: 'upload',
            outcome: state,
            reason: state === 'done' ? null : this.reason,
            status: this.status,
            field: this.options.field,
            bytes: this.total,
            sentBytes: this.loaded,
            durationMs: this.elapsedMs,
            attempts: this.attempts,
        });

        this.resolveDone(this);
    }

    /** Resolves after `ms`, or at once when the browser comes back online or the player saves */
    wait(ms) {
        return new Promise((resolve) => {
            // A short wait is no news for the status line
            if (ms >= 1000) {
                this.waitingUntil = Date.now() + ms;
                this.changed();
            }

            const done = () => {
                window.clearTimeout(timer);
                window.removeEventListener('online', done);
                this.wake = null;
                this.waitingUntil = null;
                resolve();
            };
            const timer = window.setTimeout(done, ms);

            window.addEventListener('online', done);
            this.wake = done;
        });
    }

    async attempt() {
        if (this.signed === null || Date.now() > this.signed.expiresAtMs - RESIGN_MARGIN_MS) {
            const signing = await this.sign();

            if (signing !== null) {
                return signing;
            }
        }

        return this.put();
    }

    /** null when signed, else the attempt's outcome */
    async sign() {
        let response;

        try {
            response = await fetch(this.options.startUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-Token': this.options.csrfToken,
                },
                body: JSON.stringify({
                    bytes: this.file.size,
                    type: this.type,
                    name: this.file.name,
                    token: this.token,
                    replaces: this.replaces,
                }),
            });
        } catch (error) {
            return { reason: 'network' };
        }

        if (response.status >= 500 && response.status !== 503) {
            return { reason: 'sign_failed', status: response.status };
        }

        // Refused (too large, type, signed out, limit) or storage unavailable (503): the form can still take the photo
        if (!response.ok) {
            return { reason: 'refused', status: response.status, fatal: true };
        }

        let data;

        try {
            data = await response.json();
        } catch (error) {
            return { reason: 'sign_failed', status: response.status };
        }

        if (!data || typeof data.url !== 'string' || typeof data.token !== 'string') {
            return { reason: 'refused', status: response.status, fatal: true };
        }

        this.signed = data;
        this.token = data.token;
        this.replaces = null;

        return null;
    }

    put() {
        return new Promise((resolve) => {
            const xhr = new XMLHttpRequest();
            const startedAt = Date.now();
            let lastProgressAt = startedAt;
            let stalled = false;

            this.xhr = xhr;

            const watchdog = window.setInterval(() => {
                if (Date.now() - lastProgressAt > STALL_MS) {
                    stalled = true;
                    xhr.abort();
                }
            }, 2000);

            const settle = (outcome) => {
                window.clearInterval(watchdog);
                this.xhr = null;
                resolve(outcome);
            };

            xhr.open('PUT', this.signed.url);

            for (const [name, value] of Object.entries(this.signed.headers || {})) {
                // The browser sets the length itself from the file - it is signed, and it is the file's size
                if (name.toLowerCase() !== 'content-length') {
                    xhr.setRequestHeader(name, value);
                }
            }

            xhr.upload.onprogress = (event) => {
                lastProgressAt = Date.now();
                this.loaded = event.loaded;
                this.changed();
            };

            xhr.onload = () => {
                if (xhr.status >= 200 && xhr.status < 300) {
                    this.loaded = this.total;
                    settle({ ok: true });
                    return;
                }

                // Expired (a long wait between signing and sending) - signed again once; twice is the store refusing
                if (xhr.status === 403 && this.storeRefusals === 0) {
                    this.storeRefusals += 1;
                    this.signed = null;
                    settle({ reason: 'expired', status: xhr.status });
                    return;
                }

                if (xhr.status >= 500) {
                    settle({ reason: 'store_error', status: xhr.status });
                    return;
                }

                settle({ reason: 'store_refused', status: xhr.status, fatal: true });
            };

            xhr.onerror = () => {
                settle({
                    reason: 'network',
                    instant: this.loaded === 0 && Date.now() - startedAt < INSTANT_FAILURE_MS,
                });
            };

            xhr.onabort = () => {
                settle(stalled ? { reason: 'stalled' } : { reason: 'aborted', fatal: true });
            };

            xhr.send(this.file);
        });
    }
}
