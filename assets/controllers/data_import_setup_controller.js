/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { createReloadGuard } from '../data_import_reload_guard';

/**
 * The live line of an import that is being set up - "Reading your rows… 1,200 of 3,400" - on the "Sheets and columns"
 * screen (docs/features/data-import/columns-screen.md "The live line").
 *
 * The same mechanism as the reading's line (data_import_progress_controller.js, docs/features/data-import/reading.md
 * "Progress"): progress arrives by the player's own private Mercure topic (`mercure:message` events of
 * `mercure_hub_controller.js`, `type: import_progress`), and whenever no event came for a few seconds while something
 * is still worked on, the page asks the JSON endpoint instead - more and more seldom when it does not answer, and
 * not at all after ten minutes without one answer.
 *
 * The payload carries numbers and a `revision` only; the page is rendered with the revision it shows. Whenever the
 * revision changes - a sheet got its mapping, its
 * rows were read, its set-up failed - or the work ends, every `<turbo-frame data-import-live>` of the page is loaded
 * again (the page's own address, Turbo takes the frame out of it): counts, states and the preview update without a
 * reload, and the form the player may be filling in is never touched (it stands outside those frames).
 *
 * While nothing is worked on the controller only listens: no request is made on a page that has nothing to wait for.
 */

const ASK_EVERY_MS = 4000;
// An event younger than this: the stream works, no need to ask
const QUIET_MS = 4000;
// How long a page that watches (nothing works, but something may begin) keeps asking
const WATCH_FOR_MS = 120000;
const BACK_OFF_MS = [4000, 8000, 16000, 30000];
const GIVE_UP_AFTER_MS = 10 * 60 * 1000;

export default class extends Controller {
    static targets = ['line', 'spinner', 'stale', 'refresh'];

    static values = {
        importId: String,
        url: String,
        working: Boolean,
        // The sheets' states as the page was rendered: a sheet that finishes before the first answer is seen too
        revision: String,
        // Where to go when a live part can not be loaded again (the import's own page)
        fallbackUrl: String,
        workingText: String,
        rowsText: String,
        // "Saved - your rows are being read again.": the flash a Save brings - gone once they are read
        savedText: String,
        // A page without live frames (the add review): loaded again as a whole once the reading ends
        reloadPage: Boolean,
        // Keep asking for a while although nothing works right now: a save a moment ago may not have begun yet
        watch: Boolean,
        // The puzzles' pages (docs/features/data-import/pairing-screens.md "The live line"): the pairing of the
        // import's puzzles with the database is followed too - `data.pairing` of the same payload
        pairing: Boolean,
        pairingText: String,
        searchingText: String,
    };

    connect() {
        this.lastEventAt = 0;
        this.asking = false;
        this.stopped = false;
        this.failures = 0;
        this.failingSince = 0;
        this.revision = this.revisionValue || null;
        this.working = this.workingValue;
        this.watchUntil = this.watchValue ? Date.now() + WATCH_FOR_MS : 0;

        // The reading was over before the page was rendered: "your rows are being read again" is not true any more
        if (!this.working) {
            this.dropSavedFlash();
        }
        this.numbers = new Intl.NumberFormat(document.documentElement.lang || undefined);

        this.onMessage = (event) => {
            const data = event.detail;

            if (!data || data.type !== 'import_progress' || data.importId !== this.importIdValue) {
                return;
            }

            this.lastEventAt = Date.now();
            this.show(data);
        };

        // A live part the answer does not hold (signed out, the import discarded or past its set-up, an error
        // page): Turbo would leave the frame as it is and log an error - a full visit shows what is instead
        this.onFrameMissing = (event) => {
            if (!(event.target instanceof Element) || !event.target.matches('turbo-frame[data-import-live]')) {
                return;
            }

            event.preventDefault();
            this.stopped = true;
            window.clearTimeout(this.timer);
            event.detail.visit(this.fallbackUrlValue || window.location.href);
        };

        // The page never loads itself again against the player: not once they are leaving it (an answer of a poll
        // that arrives after a click would throw them back here - the request on its way is aborted), not under a
        // form they touched (data_import_reload_guard.js)
        this.guard = createReloadGuard();
        this.guard.whenLeaving(() => {
            this.stopped = true;
            window.clearTimeout(this.timer);

            if (this.request) {
                this.request.abort();
            }
        });

        document.addEventListener('mercure:message', this.onMessage);
        document.addEventListener('turbo:frame-missing', this.onFrameMissing);

        if (this.working || this.watchUntil > 0) {
            // What happened between the page's rendering and now is asked for at once
            this.ask(true).then(() => this.askLater(ASK_EVERY_MS));
        }
    }

    disconnect() {
        if (this.guard) {
            this.guard.dispose();
        }

        if (this.request) {
            this.request.abort();
        }

        document.removeEventListener('mercure:message', this.onMessage);
        document.removeEventListener('turbo:frame-missing', this.onFrameMissing);
        this.stopped = true;
        window.clearTimeout(this.timer);
    }

    askLater(ms) {
        window.clearTimeout(this.timer);

        if (this.stopped || (!this.working && Date.now() >= this.watchUntil)) {
            return;
        }

        this.timer = window.setTimeout(async () => {
            await this.ask(false);
            this.askLater(this.failures > 0 ? BACK_OFF_MS[Math.min(this.failures, BACK_OFF_MS.length) - 1] : ASK_EVERY_MS);
        }, ms);
    }

    async ask(now) {
        if (this.asking || this.stopped) {
            return;
        }

        if (!now && (document.hidden || Date.now() - this.lastEventAt < QUIET_MS)) {
            return;
        }

        this.asking = true;

        try {
            this.request = new AbortController();

            const response = await fetch(this.urlValue, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: this.request.signal,
            });
            // The player went on to another page while the answer was on its way: nothing of it is for that page
            if (this.stopped || this.guard.leaving || !this.element.isConnected) {
                return;
            }

            const isJson = (response.headers.get('Content-Type') || '').includes('json');

            // Signed out meanwhile, or the import was discarded in another tab: the page itself says what is
            if ([401, 403, 404].includes(response.status) || (response.ok && (response.redirected || !isJson))) {
                this.stopped = true;
                window.location.reload();

                return;
            }

            if (!response.ok) {
                this.failed();

                return;
            }

            this.failures = 0;
            this.failingSince = 0;
            const data = await response.json();

            if (this.stopped || this.guard.leaving || !this.element.isConnected) {
                return;
            }

            this.show(data);
        } catch (error) {
            // Aborted because the player is leaving the page: not a failure
            if (error && error.name === 'AbortError') {
                return;
            }

            // Offline or a deploy
            this.failed();
        } finally {
            this.asking = false;
        }
    }

    failed() {
        this.failures += 1;
        this.failingSince = this.failingSince || Date.now();

        if (Date.now() - this.failingSince < GIVE_UP_AFTER_MS) {
            return;
        }

        this.stopped = true;
        window.clearTimeout(this.timer);

        if (this.hasSpinnerTarget) {
            this.spinnerTarget.hidden = true;
        }

        if (this.hasStaleTarget) {
            this.staleTarget.hidden = false;
        }
    }

    show(data) {
        const wasWorking = this.working;
        // On a puzzles' page the pairing counts too: its run and whether it came to rest are part of the revision
        const pairing = this.pairingValue && data.pairing && typeof data.pairing === 'object' ? data.pairing : null;
        const revision = typeof data.revision === 'string'
            ? (pairing !== null ? `${data.revision}|${pairing.revision}` : data.revision)
            : null;
        const changed = this.revision !== null && revision !== null && revision !== this.revision;

        if (revision !== null) {
            this.revision = revision;
        }

        const reading = data.working === true;

        this.working = reading || (pairing !== null && pairing.running === true);
        this.element.hidden = !this.working;

        if (this.working && this.hasLineTarget) {
            if (reading || pairing === null) {
                this.lineTarget.textContent = Number.isInteger(data.rowsToParse) && data.rowsToParse > 0
                    ? this.rowsTextValue
                        .replace('%done%', this.numbers.format(data.rowsParsed || 0))
                        .replace('%total%', this.numbers.format(data.rowsToParse))
                    : this.workingTextValue;
            } else {
                this.lineTarget.textContent = Number.isInteger(pairing.searching) && pairing.searching > 0 && this.searchingTextValue
                    ? this.searchingTextValue.replace('%left%', this.numbers.format(pairing.searching))
                    : this.pairingTextValue;
            }
        }

        if (changed || wasWorking !== this.working) {
            this.reloadFrames();
        }

        if (!this.working) {
            this.dropSavedFlash();
        }

        // A page without live frames is loaded again when what it shows is out of date: the work ended, or a sheet's
        // or the matching's state moved on since the first answer. (Work that is found to be running - the page did
        // not know about the matching - is only followed from then on: no reload for that.)
        if ((changed || (wasWorking && !this.working)) && this.reloadPageValue) {
            this.reloadPage();
        }

        if (this.working && !wasWorking) {
            // Work began again (a change saved in another tab): the line is kept moving from here on
            this.askLater(ASK_EVERY_MS);
        }
    }

    /**
     * The whole page is loaded again - unless the controller was disconnected meanwhile (an answer that arrives
     * after Turbo went on to another page must not reload THAT page), or the player is in the middle of something
     * here: a field they changed, a form control they stand in. Then a small "Refresh" link is shown instead.
     */
    reloadPage() {
        const verdict = this.guard.verdict({
            connected: this.element.isConnected,
            stopped: this.stopped,
            activeElement: document.activeElement,
        });

        if (verdict === 'no') {
            return;
        }

        this.stopped = true;
        window.clearTimeout(this.timer);

        if (verdict === 'refresh') {
            this.element.hidden = false;

            if (this.hasSpinnerTarget) {
                this.spinnerTarget.hidden = true;
            }

            if (this.hasLineTarget) {
                this.lineTarget.hidden = true;
            }

            if (this.hasRefreshTarget) {
                this.refreshTarget.hidden = false;
            }

            return;
        }

        window.location.reload();
    }

    /**
     * The reading ended: the flash that says "your rows are being read again" would be wrong from now on.
     */
    dropSavedFlash() {
        const saved = (this.savedTextValue || '').trim();

        if (saved === '') {
            return;
        }

        document.querySelectorAll('.alert-success[role="alert"]').forEach((alert) => {
            if (alert.textContent.trim().startsWith(saved)) {
                // The layout wraps each flash in a container of its own: that goes too - never anything larger
                const wrapper = alert.parentElement;
                (wrapper && wrapper.matches('.container.mt-3') && wrapper.children.length === 1 ? wrapper : alert).remove();
            }
        });
    }

    /**
     * The page's live parts are fetched again: the page's own address, of which Turbo takes each frame.
     */
    reloadFrames() {
        document.querySelectorAll('turbo-frame[data-import-live]').forEach((frame) => {
            if (frame.getAttribute('src')) {
                frame.reload();
            } else {
                frame.src = window.location.href;
            }
        });
    }
}
