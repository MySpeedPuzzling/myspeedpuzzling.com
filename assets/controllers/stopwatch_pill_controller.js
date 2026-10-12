/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { subscribe, wake } from '../page_ticker.js';
import { documentReceipt } from '../document_receipt.js';
import {
    LAST_STATE_EVENT,
    LAST_STATE_KEY,
    clockOffsetMs,
    elapsedSeconds,
    format,
    nextChangeAt,
    parseLastStates,
    resumedAfterRender,
    stoppedAfterRender,
} from '../stopwatch_elapsed.js';

// The server's clock minus this device's, per render epoch: measured once per render - from when the page's HTML
// arrived (assets/document_receipt.js, review F5), else when the render first connects - and reused when the same
// render connects again: a page restored from a snapshot reconnects long after it was rendered, and measuring then would
// count the page's age as clock difference (docs/features/navigation ADD-03).
const offsets = new Map();
const MAX_REMEMBERED_RENDERS = 50;

function offsetFor(renderedEpoch) {
    if (!offsets.has(renderedEpoch)) {
        if (offsets.size >= MAX_REMEMBERED_RENDERS) {
            offsets.delete(offsets.keys().next().value);
        }

        offsets.set(renderedEpoch, clockOffsetMs(renderedEpoch, documentReceipt(document), Date.now()));
    }

    return offsets.get(renderedEpoch);
}

function readLastStates() {
    try {
        return parseLastStates(window.localStorage.getItem(LAST_STATE_KEY));
    } catch {
        return {};
    }
}

/**
 * The running stopwatch's elapsed time (templates/navigation/_stopwatch_elapsed.html.twig): counts on from the digits
 * the server rendered, once a second through the page's one timer (assets/page_ticker.js - asleep while the page is
 * hidden), and hides the pill (or the element itself outside a pill) once this device paused, reset or finished that
 * stopwatch after the page was rendered. A hidden pill keeps listening: resumed on this device (another tab included),
 * it shows again and counts from the resume's elapsed time (review F6). Only text is written while it ticks; the width
 * was fixed at render.
 */
export default class extends Controller {
    static targets = ['full', 'compact'];

    static values = {
        stopwatchId: String,
        // Unix seconds: when the running lap started, and when the server rendered these digits
        lapStarted: Number,
        rendered: Number,
        closedSeconds: Number,
    };

    connect() {
        this.offset = offsetFor(this.renderedValue);
        // What the ticker counts from - the render's numbers until a resume on this device says otherwise
        this.lapStarted = this.lapStartedValue;
        this.closedSeconds = this.closedSecondsValue;
        this.hiddenByState = false;
        this.onLastState = () => this.applyLastState();

        window.addEventListener('storage', this.onLastState);
        window.addEventListener(LAST_STATE_EVENT, this.onLastState);
        this.applyLastState();
    }

    disconnect() {
        window.removeEventListener('storage', this.onLastState);
        window.removeEventListener(LAST_STATE_EVENT, this.onLastState);
        this.stopTicking();
    }

    applyLastState() {
        const states = readLastStates();

        if (stoppedAfterRender(states, this.stopwatchIdValue, this.renderedValue, this.offset)) {
            this.hide();

            return;
        }

        const resumed = resumedAfterRender(states, this.stopwatchIdValue, this.renderedValue, this.offset);

        if (resumed !== null) {
            this.lapStarted = resumed.lapStarted;
            this.closedSeconds = resumed.closedSeconds;
        }

        this.show();
    }

    hide() {
        this.container().hidden = true;
        this.hiddenByState = true;
        this.stopTicking();
    }

    show() {
        if (this.hiddenByState) {
            this.container().hidden = false;
            this.hiddenByState = false;
        }

        if (this.unsubscribe) {
            // Counted from a resume now: the ticker runs it at once and plans its next change anew
            wake();
        } else {
            this.unsubscribe = subscribe((now) => this.tick(now));
        }
    }

    stopTicking() {
        if (this.unsubscribe) {
            this.unsubscribe();
            this.unsubscribe = null;
        }
    }

    container() {
        return this.element.closest('[data-stopwatch-pill]') ?? this.element;
    }

    tick(now) {
        const serverNow = (now + this.offset) / 1000;
        const seconds = elapsedSeconds(this.lapStarted, this.closedSeconds, serverNow);

        this.write(this.fullTargets, format(seconds, false));
        this.write(this.compactTargets, format(seconds, true));

        return nextChangeAt(this.lapStarted, serverNow) * 1000 - this.offset;
    }

    write(targets, text) {
        for (const target of targets) {
            if (target.textContent !== text) {
                target.textContent = text;
            }
        }
    }
}
