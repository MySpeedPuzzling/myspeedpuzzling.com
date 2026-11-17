/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { CompetitorPageLive, roundsOf, roundView } from '../competitor_page_live.js';
import { clockParts, formatCountdown } from '../round_stopwatch_clock.js';

const FETCH_TIMEOUT_MS = 10000;
const PHASE_CLASSES = ['is-phase-idle', 'is-phase-scheduled', 'is-phase-running', 'is-phase-times_up', 'is-phase-stopped'];
const BADGE_CLASSES = { scheduled: ['bg-primary'], running: ['bg-success'], times_up: ['bg-danger'], stopped: ['bg-warning', 'text-dark'] };
const ALL_BADGE_CLASSES = ['bg-primary', 'bg-success', 'bg-danger', 'bg-warning', 'text-dark'];

/**
 * The live clock of the public competitor page (templates/competitor_page/page.html.twig,
 * docs/features/competitions-management/competitor-pages.md "Live clock", competition toolkit B5) - the DOM adapter of
 * assets/competitor_page_live.js: the state fetch, the page HTML for changed cards, visibility and the screen wake lock.
 *
 * Each round card (`[data-competitor-round]`) gets its phase in `data-competitor-round-phase` + an `is-phase-*` class,
 * its badge, and the clock (`[data-competitor-clock]`): the countdown to a scheduled start, the elapsed time while it
 * runs (with the time left of the limit), frozen when stopped.
 *
 * For B8 ("Done - propose my time"): `tapNowMs()` reads the server's time synchronously from the same clock,
 * `roundView(roundId)` the card's phase; every phase change dispatches `competitor-page:phase` ({roundId, phase}) on
 * the root, and `[data-competitor-round-actions]` in each card is the empty slot for the button.
 */
export default class extends Controller {
    static values = {
        state: Object,
        stateUrl: String,
        serverNowMs: Number,
        mercureUrl: String,
        labels: Object,
    };

    connect() {
        this.phases = new Map();
        this.texts = new WeakMap();
        this.wakeSentinel = null;
        this.wakeWanted = false;
        this.contentLoading = null;
        this.contentAgain = false;

        const state = this.hasStateValue && Array.isArray(this.stateValue.rounds) ? this.stateValue : { rounds: [] };

        if (typeof state.serverNowMs !== 'number' && this.serverNowMsValue) {
            state.serverNowMs = this.serverNowMsValue;
        }

        this.live = new CompetitorPageLive({
            state,
            hubUrl: this.mercureUrlValue,
            fetchState: () => this.fetchState(),
            render: (current, nowMs) => this.render(current, nowMs),
            reloadContent: (changed) => this.reloadContent(changed),
            wakeLock: (on) => this.wakeLock(on),
            isVisible: () => document.visibilityState === 'visible',
        });

        this.onVisibility = () => {
            if (document.visibilityState === 'visible') {
                this.live.onVisible();
            }
        };
        document.addEventListener('visibilitychange', this.onVisibility);

        this.live.start();
    }

    disconnect() {
        document.removeEventListener('visibilitychange', this.onVisibility);
        this.live?.close();
        this.live = null;
        this.wakeLock(false);
    }

    /**
     * The server's time now (ms) - read at a "Stop" tap, before anything asynchronous (B8).
     */
    tapNowMs() {
        return this.live?.tapNowMs() ?? Date.now();
    }

    roundView(roundId) {
        return this.live?.roundView(roundId) ?? null;
    }

    async fetchState() {
        const answer = await this.get(this.stateUrlValue, 'application/json');

        if (answer.status !== 'ok') {
            return answer;
        }

        try {
            return { status: 'ok', state: await answer.response.json(), sentAtMs: answer.sentAtMs, receivedAtMs: answer.receivedAtMs };
        } catch {
            return { status: 'error' };
        }
    }

    async get(url, accept) {
        if (!url) {
            return { status: 'error' };
        }

        const controller = typeof AbortController === 'function' ? new AbortController() : null;
        const timer = controller ? setTimeout(() => controller.abort(), FETCH_TIMEOUT_MS) : null;
        const sentAtMs = Date.now();

        try {
            const response = await fetch(url, {
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { Accept: accept },
                signal: controller?.signal,
            });
            const receivedAtMs = Date.now();

            if (response.status === 404) {
                return { status: 'gone' };
            }

            return response.ok ? { status: 'ok', response, sentAtMs, receivedAtMs } : { status: 'error' };
        } catch {
            return { status: 'error' };
        } finally {
            if (timer !== null) {
                clearTimeout(timer);
            }
        }
    }

    /**
     * Cards whose content changed (a reveal, a result, who plays): drawn again from the page's own HTML - the markup
     * lives in Twig only. One load at a time; a change meanwhile loads once more after it.
     */
    reloadContent(changed) {
        if (this.contentLoading !== null) {
            this.contentAgain = true;

            return;
        }

        this.contentLoading = (async () => {
            const answer = await this.get(window.location.href, 'text/html');

            if (answer.status === 'ok' && this.element.isConnected && this.live !== null) {
                const html = await answer.response.text();
                this.swapCards(new DOMParser().parseFromString(html, 'text/html'), changed);
                this.live.tick();
            }
        })().catch(() => {}).finally(() => {
            this.contentLoading = null;

            if (this.contentAgain && this.live !== null) {
                this.contentAgain = false;
                this.reloadContent({ all: true, roundIds: [] });
            }
        });
    }

    swapCards(doc, changed) {
        const fresh = doc.querySelector('[data-competitor-rounds]');
        const current = this.element.querySelector('[data-competitor-rounds]');

        if (fresh === null || current === null) {
            return;
        }

        if (changed.all) {
            current.replaceWith(document.importNode(fresh, true));
        } else {
            for (const roundId of changed.roundIds) {
                const next = fresh.querySelector(`[data-competitor-round="${CSS.escape(roundId)}"]`);
                const card = current.querySelector(`[data-competitor-round="${CSS.escape(roundId)}"]`);

                if (next !== null && card !== null) {
                    card.replaceWith(document.importNode(next, true));
                }
            }
        }

        // New cards: their phase is set again on the next draw
        this.phases.clear();
    }

    render(state, nowMs) {
        if (!this.element.isConnected) {
            return;
        }

        for (const round of roundsOf(state)) {
            const card = this.element.querySelector(`[data-competitor-round="${CSS.escape(round.roundId)}"]`);

            if (card !== null) {
                this.renderCard(card, round, roundView(round, nowMs));
            }
        }
    }

    renderCard(card, round, view) {
        const labels = this.labelsValue ?? {};
        const phase = view.phase;

        if (this.phases.get(round.roundId) !== phase) {
            this.phases.set(round.roundId, phase);
            card.dataset.competitorRoundPhase = phase;
            card.classList.remove(...PHASE_CLASSES);
            card.classList.add(`is-phase-${phase}`);

            const badge = card.querySelector('[data-competitor-round-badge]');

            if (badge !== null) {
                badge.classList.remove(...ALL_BADGE_CLASSES);
                badge.classList.add(...(BADGE_CLASSES[phase] ?? []));
                badge.hidden = !(phase in BADGE_CLASSES);
                badge.textContent = labels[phase] ?? '';
                badge.toggleAttribute('data-competitor-round-running', phase === 'running');
            }

            this.dispatch('phase', { detail: { roundId: round.roundId, phase } });
        }

        const clock = card.querySelector('[data-competitor-clock]');

        if (clock === null) {
            return;
        }

        // A stopped clock only on the current round (a pause) - a round stopped at its end is history, not a clock
        const shown = phase === 'scheduled' || phase === 'running' || phase === 'times_up' || (phase === 'stopped' && round.current === true);
        clock.hidden = !shown;

        if (!shown) {
            return;
        }

        const time = clock.querySelector('[data-competitor-clock-time]');
        const note = clock.querySelector('[data-competitor-clock-note]');

        if (phase === 'scheduled') {
            this.setText(time, formatCountdown(view.countdownMs));
            this.setText(note, (labels.starts_in ?? '%time%').replace('%time%', formatCountdown(view.countdownMs)));
        } else {
            const parts = clockParts(view.elapsedMs);
            this.setText(time, `${parts.hours}:${parts.minutes}:${parts.seconds}`);

            let text = '';

            if (phase === 'running' && view.remainingMs !== null) {
                const left = clockParts(view.remainingMs);
                text = (labels.left ?? '%time%').replace('%time%', `${left.hours}:${left.minutes}:${left.seconds}`);
            } else if (phase === 'times_up') {
                text = labels.times_up ?? '';
            } else if (phase === 'stopped') {
                text = labels.stopped ?? '';
            }

            this.setText(note, text);
        }
    }

    setText(element, text) {
        if (element !== null && this.texts.get(element) !== text) {
            this.texts.set(element, text);
            element.textContent = text;
        }
    }

    wakeLock(on) {
        this.wakeWanted = on;

        if (!on) {
            const sentinel = this.wakeSentinel;
            this.wakeSentinel = null;
            sentinel?.release?.().catch?.(() => {});

            return;
        }

        if (!navigator.wakeLock || (this.wakeSentinel !== null && !this.wakeSentinel.released) || document.visibilityState !== 'visible') {
            return;
        }

        navigator.wakeLock.request('screen').then((sentinel) => {
            if (this.wakeWanted && this.live !== null) {
                this.wakeSentinel = sentinel;
            } else {
                sentinel.release().catch(() => {});
            }
        }).catch(() => {
            // Unsupported or refused (low battery): the phone sleeps as it would
        });
    }
}
