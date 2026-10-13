/**
 * Keeping the participant's screen on for the whole round (docs/features/online-competitions/README.md §5.5 "Browser
 * realities"): the Screen Wake Lock API, with its rules -
 * - Safari grants the *first* lock only right after a tap: request() is called from the "I'm ready" tap (and from the
 *   first tap after a reload);
 * - every browser releases the lock when the page is hidden: it is asked for again on every return to visible - never
 *   given up because it was released (the personal stopwatch's mistake: its `release` listener unticked the box);
 * - `NotAllowedError` (power saving) is a normal outcome, reported as `denied`; no API at all is `unsupported` (iOS
 *   home-screen apps before 18.4) - the checklist then says to keep the screen on by hand.
 *
 * States: idle (not wanted yet) · pending (asked, or wanted while hidden) · active · released (the browser let go - asked
 * again when visible) · denied · unsupported.
 *
 * No DOM at import: navigator.wakeLock and the document are injected - pinned by tests/online-devices-harness.mjs.
 */

export class ScreenWakeLock {
    /**
     * @param {object} [options]
     * @param {{request: function(string): Promise<object>}|null} [options.wakeLock]  navigator.wakeLock
     * @param {Document|null} [options.document]
     * @param {function(string): void} [options.onChange]
     */
    constructor({
        wakeLock = globalThis.navigator?.wakeLock ?? null,
        document = globalThis.document ?? null,
        onChange = () => {},
    } = {}) {
        this.api = wakeLock;
        this.document = document;
        this.onChange = onChange;
        this.wanted = false;
        this.sentinel = null;
        this.requesting = null;
        this.state = wakeLock && typeof wakeLock.request === 'function' ? 'idle' : 'unsupported';
        this.onVisibility = () => this.visibilityChanged();
        this.attached = false;
    }

    isSupported() {
        return this.api !== null && typeof this.api?.request === 'function';
    }

    /**
     * Listens for the page becoming visible again (and asks for the lock then).
     */
    attach() {
        if (!this.attached && this.document) {
            this.document.addEventListener('visibilitychange', this.onVisibility);
            this.attached = true;
        }

        return this;
    }

    detach() {
        if (this.attached && this.document) {
            this.document.removeEventListener('visibilitychange', this.onVisibility);
            this.attached = false;
        }
    }

    /**
     * Wanted from now on - asked for at once when the page is visible (call it from a tap).
     *
     * @returns {Promise<string>} the state after the request
     */
    request() {
        this.wanted = true;

        if (!this.isSupported()) {
            this.set('unsupported');

            return Promise.resolve(this.state);
        }

        if (this.sentinel !== null && this.sentinel.released !== true) {
            this.set('active');

            return Promise.resolve(this.state);
        }

        if (!this.visible()) {
            this.set('pending');

            return Promise.resolve(this.state);
        }

        return this.acquire();
    }

    /**
     * Not wanted any more: let it go.
     */
    async release() {
        this.wanted = false;
        const sentinel = this.sentinel;
        this.sentinel = null;

        if (sentinel !== null) {
            try {
                await sentinel.release();
            } catch (e) {
                // Already released
            }
        }

        this.set(this.isSupported() ? 'idle' : 'unsupported');
    }

    // ---- internals

    visible() {
        return !this.document || this.document.visibilityState !== 'hidden';
    }

    visibilityChanged() {
        if (this.wanted && this.visible() && (this.sentinel === null || this.sentinel.released === true)) {
            this.acquire();
        }
    }

    acquire() {
        if (this.requesting !== null) {
            return this.requesting;
        }

        this.set('pending');
        let asked;

        // Called right here, inside the tap that asked for it (Safari's user-activation window)
        try {
            asked = Promise.resolve(this.api.request('screen'));
        } catch (error) {
            asked = Promise.reject(error);
        }

        this.requesting = asked
            .then((sentinel) => {
                this.requesting = null;

                if (!this.wanted) {
                    sentinel?.release?.().catch?.(() => {});
                    this.set('idle');

                    return this.state;
                }

                this.sentinel = sentinel;
                sentinel?.addEventListener?.('release', () => {
                    if (this.sentinel === sentinel) {
                        this.sentinel = null;
                        // Hidden pages lose it - asked again on the way back (visibilityChanged)
                        this.set(this.wanted ? 'released' : 'idle');
                    }
                });
                this.set('active');

                return this.state;
            })
            .catch((error) => {
                this.requesting = null;
                this.set(error?.name === 'NotAllowedError' || error?.name === 'SecurityError' ? 'denied' : 'released');

                return this.state;
            });

        return this.requesting;
    }

    set(state) {
        if (this.state === state) {
            return;
        }

        this.state = state;

        try {
            this.onChange(state);
        } catch (e) {
            // The page's listener must never break the lock
        }
    }
}
