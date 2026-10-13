/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import {
    BIG_COUNTDOWN_MS,
    clockParts,
    countdownSeconds,
    createServerClock,
    formatCountdown,
    isNewerState,
    startedAtMs,
    stopwatchView,
} from '../round_stopwatch_clock.js';

const POLL_FALLBACK_MS = 3000;
// Around a scheduled start the state is asked for again - a cancel whose live update got lost is seen before the start,
// and the clock offset is fresh for the moment that counts
const CHECK_BEFORE_START_MS = 2000;
const CHECK_AFTER_START_MS = 1000;
// setTimeout() takes 32-bit milliseconds - a start further ahead is checked again on the way
const MAX_TIMEOUT_MS = 2 ** 31 - 1;

/**
 * The round stopwatch's clock (docs/features/competitions-management/README.md "Round Stopwatch") on the projector page
 * (templates/round_stopwatch.html.twig) and the stopwatch control page (templates/manage_round_stopwatch.html.twig):
 * the state the page was rendered with (RoundStopwatchState), kept current by the round's public Mercure topic, with
 * polling while that is down. The time shown comes from the server's clock: the offset of the state fetch with the
 * shortest round trip (round_stopwatch_clock.js).
 *
 * Before a scheduled start it counts down ("Starts in 0:00:09" - the `countdown` and `status` targets, the last ten
 * seconds big in `bigCountdown` on the projector) and runs from that moment on by itself. Elements with
 * `data-stopwatch-when="idle scheduled running times_up stopped"` (any of them) are shown only in those phases - the
 * control page's buttons and badges.
 */
export default class extends Controller {
    static targets = ['clock', 'hours', 'minutes', 'seconds', 'status', 'bigCountdown', 'countdown'];

    static values = {
        state: Object,
        serverNowMs: Number,
        mercureUrl: String,
        topic: String,
        stateUrl: String,
    };

    connect() {
        this.rafId = null;
        this.pollId = null;
        this.eventSource = null;
        this.startTimers = [];
        this.lastPhase = null;
        this.lastTexts = new Map();

        this.clock = createServerClock();
        // The page's own server time - no round trip known yet; the first state fetch refines it
        if (this.serverNowMsValue) {
            this.clock.hint(this.serverNowMsValue - Date.now());
        }

        this.state = this.stateValue ?? {};
        this.applied();

        this.connectMercure();
        this.startPolling();
        // Two quick samples for the clock offset - the shortest round trip wins
        this.refetch();
        this.secondSample = setTimeout(() => this.refetch(), 1500);

        this.onVisibility = () => {
            if (document.visibilityState === 'visible') {
                this.refetch();
            }
        };
        document.addEventListener('visibilitychange', this.onVisibility);
    }

    disconnect() {
        this.stopRaf();
        this.stopPolling();
        this.clearStartTimers();
        clearTimeout(this.secondSample);

        if (this.eventSource) {
            this.eventSource.close();
            this.eventSource = null;
        }

        document.removeEventListener('visibilitychange', this.onVisibility);
    }

    connectMercure() {
        if (!this.mercureUrlValue || !this.topicValue) {
            return;
        }

        const url = new URL(this.mercureUrlValue);
        url.searchParams.append('topic', this.topicValue);
        this.eventSource = new EventSource(url);

        this.eventSource.addEventListener('open', () => {
            // Live again: no polling, and one fetch for whatever happened while the connection was down
            this.stopPolling();
            this.refetch();
        });

        this.eventSource.addEventListener('message', (event) => {
            try {
                this.apply(JSON.parse(event.data));
            } catch {
                this.refetch();
            }
        });

        this.eventSource.addEventListener('error', () => {
            // Dropped or refused - the browser retries on its own, polling keeps the page current meanwhile
            this.startPolling();
        });
    }

    startPolling() {
        if (this.pollId !== null) {
            return;
        }

        this.pollId = setInterval(() => {
            if (document.visibilityState === 'visible') {
                this.refetch();
            }
        }, POLL_FALLBACK_MS);
    }

    stopPolling() {
        if (this.pollId !== null) {
            clearInterval(this.pollId);
            this.pollId = null;
        }
    }

    async refetch() {
        if (!this.stateUrlValue) {
            return;
        }

        const sentAtMs = Date.now();

        try {
            const response = await fetch(this.stateUrlValue, { cache: 'no-store' });
            const receivedAtMs = Date.now();

            if (!response.ok) {
                return;
            }

            const data = await response.json();

            if (typeof data.serverNowMs === 'number') {
                this.clock.observe({ sentAtMs, receivedAtMs, serverNowMs: data.serverNowMs });
            } else if (data.serverNow) {
                this.clock.observe({ sentAtMs, receivedAtMs, serverNowMs: Date.parse(data.serverNow) });
            }

            this.apply(data);
        } catch {
            // The next signal (a live update, the tab shown again, a poll) tries again
        }
    }

    apply(data) {
        if (!data || typeof data !== 'object' || !('status' in data) || !isNewerState(this.state, data)) {
            return;
        }

        this.state = data;
        this.applied();
    }

    applied() {
        this.scheduleStartChecks();
        this.lastTexts.clear();
        this.render();
    }

    serverNow() {
        return this.clock.serverNow();
    }

    /**
     * Fetches the state shortly before and after a scheduled start.
     */
    scheduleStartChecks() {
        this.clearStartTimers();

        if (this.state?.status !== 'scheduled') {
            return;
        }

        const start = startedAtMs(this.state);

        if (start === null) {
            return;
        }

        for (const at of [start - CHECK_BEFORE_START_MS, start + CHECK_AFTER_START_MS]) {
            const delay = at - this.serverNow();

            if (delay > 0) {
                this.startTimers.push(setTimeout(() => {
                    if (delay > MAX_TIMEOUT_MS) {
                        this.scheduleStartChecks();
                    } else {
                        this.refetch();
                    }
                }, Math.min(delay, MAX_TIMEOUT_MS)));
            }
        }
    }

    clearStartTimers() {
        this.startTimers.forEach((timer) => clearTimeout(timer));
        this.startTimers = [];
    }

    render() {
        const view = stopwatchView(this.state, this.serverNow());

        this.renderClock(view);
        this.renderCountdown(view);
        this.renderStatus(view);

        if (view.phase !== this.lastPhase) {
            this.lastPhase = view.phase;
            this.element.dataset.stopwatchPhase = view.phase;
            this.element.querySelectorAll('[data-stopwatch-when]').forEach((element) => {
                element.hidden = !element.dataset.stopwatchWhen.split(' ').includes(view.phase);
            });
        }

        // Counting down or running: the next frame - anything else stands still until the state changes
        if (view.phase === 'scheduled' || view.phase === 'running') {
            this.startRaf();
        } else {
            this.stopRaf();
        }
    }

    renderClock(view) {
        if (!this.hasHoursTarget) {
            return;
        }

        const parts = clockParts(view.elapsedMs);
        this.setText(this.hoursTarget, parts.hours);
        this.setText(this.minutesTarget, parts.minutes);
        this.setText(this.secondsTarget, parts.seconds);
    }

    renderCountdown(view) {
        const counting = view.phase === 'scheduled';
        const big = counting && view.countdownMs <= BIG_COUNTDOWN_MS && view.countdownMs > 0;

        if (this.hasBigCountdownTarget) {
            this.bigCountdownTarget.hidden = !big;

            if (big) {
                this.setText(this.bigCountdownTarget, String(countdownSeconds(view.countdownMs)));
            }

            if (this.hasClockTarget) {
                this.clockTarget.hidden = big;
            }
        }

        if (this.hasCountdownTarget) {
            this.setText(this.countdownTarget, counting ? this.startsInText(view) : '');
        }
    }

    renderStatus(view) {
        if (!this.hasStatusTarget) {
            return;
        }

        const labels = this.statusTarget.dataset;
        const texts = {
            idle: labels.labelNotStarted ?? '',
            scheduled: this.startsInText(view),
            running: labels.labelRunning ?? '',
            times_up: labels.labelTimesUp ?? '',
            stopped: labels.labelStopped ?? '',
        };
        const colours = { idle: 'text-muted', scheduled: 'text-primary', running: 'text-success', times_up: 'text-danger', stopped: 'text-warning' };

        this.setText(this.statusTarget, texts[view.phase] ?? '');

        if (this.statusTarget.dataset.phase !== view.phase) {
            this.statusTarget.dataset.phase = view.phase;
            this.statusTarget.classList.remove(...Object.values(colours));
            this.statusTarget.classList.add(colours[view.phase] ?? 'text-muted');
        }
    }

    startsInText(view) {
        const template = this.element.dataset.labelStartsIn ?? '%time%';

        return template.replace('%time%', formatCountdown(view.countdownMs));
    }

    setText(element, text) {
        if (this.lastTexts.get(element) !== text) {
            this.lastTexts.set(element, text);
            element.textContent = text;
        }
    }

    startRaf() {
        if (this.rafId !== null) {
            return;
        }

        this.rafId = requestAnimationFrame(() => {
            this.rafId = null;
            this.render();
        });
    }

    stopRaf() {
        if (this.rafId !== null) {
            cancelAnimationFrame(this.rafId);
            this.rafId = null;
        }
    }
}
