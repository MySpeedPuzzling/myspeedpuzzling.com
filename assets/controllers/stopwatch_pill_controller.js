/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { subscribe } from '../page_ticker.js';
import {
    LAST_STATE_EVENT,
    LAST_STATE_KEY,
    elapsedSeconds,
    format,
    nextChangeAt,
    parseLastState,
    stoppedAfterRender,
} from '../stopwatch_elapsed.js';

// The server's clock minus this device's, per render epoch: measured when a render first connects and reused when the
// same render connects again - a page restored from a snapshot reconnects long after it was rendered, and measuring
// then would count the page's age as clock difference (docs/features/navigation ADD-03).
const offsets = new Map();
const MAX_REMEMBERED_RENDERS = 50;

function offsetFor(renderedEpoch) {
    if (!offsets.has(renderedEpoch)) {
        if (offsets.size >= MAX_REMEMBERED_RENDERS) {
            offsets.delete(offsets.keys().next().value);
        }

        offsets.set(renderedEpoch, renderedEpoch * 1000 - Date.now());
    }

    return offsets.get(renderedEpoch);
}

function readLastState() {
    try {
        return parseLastState(window.localStorage.getItem(LAST_STATE_KEY));
    } catch {
        return null;
    }
}

/**
 * The running stopwatch's elapsed time (templates/navigation/_stopwatch_elapsed.html.twig): counts on from the digits
 * the server rendered, once a second through the page's one timer (assets/page_ticker.js - asleep while the page is
 * hidden), and hides the pill (or the element itself outside a pill) once this device paused, reset or finished that
 * stopwatch after the page was rendered. Only text is written while it ticks; the width was fixed at render.
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
        this.onLastState = () => this.hideIfStopped();

        if (this.hideIfStopped()) {
            return;
        }

        window.addEventListener('storage', this.onLastState);
        window.addEventListener(LAST_STATE_EVENT, this.onLastState);
        this.unsubscribe = subscribe((now) => this.tick(now));
    }

    disconnect() {
        this.stop();
    }

    stop() {
        window.removeEventListener('storage', this.onLastState);
        window.removeEventListener(LAST_STATE_EVENT, this.onLastState);

        if (this.unsubscribe) {
            this.unsubscribe();
            this.unsubscribe = null;
        }
    }

    tick(now) {
        const serverNow = (now + this.offset) / 1000;
        const seconds = elapsedSeconds(this.lapStartedValue, this.closedSecondsValue, serverNow);

        this.write(this.fullTargets, format(seconds, false));
        this.write(this.compactTargets, format(seconds, true));

        return nextChangeAt(this.lapStartedValue, serverNow) * 1000 - this.offset;
    }

    write(targets, text) {
        for (const target of targets) {
            if (target.textContent !== text) {
                target.textContent = text;
            }
        }
    }

    hideIfStopped() {
        if (!stoppedAfterRender(readLastState(), this.stopwatchIdValue, this.renderedValue, this.offset)) {
            return false;
        }

        (this.element.closest('[data-stopwatch-pill]') ?? this.element).hidden = true;
        this.stop();

        return true;
    }
}
