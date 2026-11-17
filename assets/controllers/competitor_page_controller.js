/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { CompetitorPageLive, roundsOf, roundView } from '../competitor_page_live.js';
import { clockParts, formatCountdown } from '../round_stopwatch_clock.js';
import {
    createMemoryStorage,
    createProposalsStorage,
    createTabLock,
    outboxSummary,
    ProposalOutbox,
    PROPOSALS_LOCK,
    proposalBody,
    proposalRequest,
} from '../competitor_proposals_outbox.js';
import { CompetitorHeartbeat, sendHeartbeat } from '../competitor_heartbeat.js';
import { currentProposal, NONE, proposalPanel, waitsForJudge } from '../competitor_proposals_view.js';

const FETCH_TIMEOUT_MS = 10000;
const PHASE_CLASSES = ['is-phase-idle', 'is-phase-scheduled', 'is-phase-running', 'is-phase-times_up', 'is-phase-stopped'];
const BADGE_CLASSES = { scheduled: ['bg-primary'], running: ['bg-success'], times_up: ['bg-danger'], stopped: ['bg-warning', 'text-dark'] };
const ALL_BADGE_CLASSES = ['bg-primary', 'bg-success', 'bg-danger', 'bg-warning', 'text-dark'];
const NIL_ID = '00000000-0000-0000-0000-000000000000';

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
 *
 * B8 - "Done - propose my time" (only when the page carries `propose-url`: the signed-in competitor's own entry with
 * time proposals on): the slot of a round is drawn from proposalPanel() (assets/competitor_proposals_view.js) on every
 * clock draw - a card drawn again from the page's HTML gets its slot back at once. The tap's time is read first,
 * synchronously; "Propose 0:49:12?" → the phone's outbox (assets/competitor_proposals_outbox.js) → the server; Undo
 * withdraws. While something waits for the judge the state is asked for every 5 s (CompetitorPageLive fastPoll).
 *
 * T2 - timing you can trust: each send carries how the tap travelled (proposalBody()); while an own round runs (or its
 * tap still waits on the phone) the page beats every 15 s to `heartbeat-url` with the round's signed token
 * (assets/competitor_heartbeat.js) - started only once the outbox was read, so a reloaded page never reports
 * "nothing unsent" over a tap it still holds.
 */
export default class extends Controller {
    static values = {
        state: Object,
        stateUrl: String,
        serverNowMs: Number,
        mercureUrl: String,
        labels: Object,
        proposeUrl: String,
        withdrawUrl: String,
        csrfToken: String,
        viewer: String,
        proposalLabels: Object,
        heartbeatUrl: String,
    };

    connect() {
        this.phases = new Map();
        this.texts = new WeakMap();
        this.wakeSentinel = null;
        this.wakeWanted = false;
        this.contentLoading = null;
        this.contentAgain = false;
        this.outbox = null;
        this.answers = new Map();
        this.confirming = new Map();
        this.slotKeys = new WeakMap();
        this.waiting = false;
        this.heartbeat = null;

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
            fastPoll: () => this.waiting,
        });

        if (this.hasProposeUrlValue && this.proposeUrlValue !== '') {
            this.startProposals();
        }

        this.onVisibility = () => {
            if (document.visibilityState === 'visible') {
                this.live.onVisible();
                this.heartbeat?.nudge();
            }
        };
        document.addEventListener('visibilitychange', this.onVisibility);

        this.live.start();
    }

    disconnect() {
        document.removeEventListener('visibilitychange', this.onVisibility);
        this.stopProposals();
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

    // ---- B8: propose my time

    startProposals() {
        this.onTap = (event) => this.tap(event);
        this.onOnline = () => {
            this.outbox?.connectionBack();
            this.heartbeat?.nudge();
        };
        this.onBeforeUnload = (event) => {
            if (this.outbox?.hasUnsent()) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        this.element.addEventListener('click', this.onTap);
        window.addEventListener('online', this.onOnline);
        window.addEventListener('beforeunload', this.onBeforeUnload);

        const send = (item) => item.action === 'withdraw'
            ? proposalRequest(this.withdrawUrlValue.replace(NIL_ID, item.id), {}, this.csrfTokenValue)
            : proposalRequest(this.proposeUrlValue.replace(NIL_ID, item.roundId), proposalBody(item, this.tapNowMs()), this.csrfTokenValue);

        createProposalsStorage().catch(() => null).then((storage) => {
            if (this.live === null) {
                return;
            }

            this.outbox = new ProposalOutbox({
                storage: storage ?? createMemoryStorage(),
                send,
                userId: this.viewerValue,
                lock: createTabLock(PROPOSALS_LOCK),
                onAnswer: (roundId, answer) => {
                    this.answers.set(roundId.toLowerCase(), answer);
                    this.drawProposals();
                },
                onChange: () => this.drawProposals(),
            });
            this.outbox.load().catch(() => {}).then(() => this.startHeartbeat());
        });
    }

    /**
     * T2: the heartbeat of the own rounds - after the outbox was read (its unsent taps go with every beat).
     */
    startHeartbeat() {
        if (this.live === null || this.outbox === null || !this.hasHeartbeatUrlValue || this.heartbeatUrlValue === '') {
            return;
        }

        const outbox = this.outbox;
        this.heartbeat = new CompetitorHeartbeat({
            send: (body) => sendHeartbeat(this.heartbeatUrlValue, body),
            active: () => this.heartbeatRounds(),
            summary: async (roundId) => {
                let items;

                try {
                    // Fresh from the storage: a tap of another tab counts too
                    items = await outbox.storage.getAll();
                } catch {
                    items = outbox.own();
                }

                return outboxSummary(items, this.viewerValue, roundId, this.confirming.get(roundId.toLowerCase())?.tappedAtMs ?? null);
            },
            clock: () => ({ atMs: this.tapNowMs(), ...(this.live?.clockSample() ?? {}) }),
            onSample: (sample) => this.live?.observeClock(sample),
            onExpired: () => this.live?.requestRefresh(1000),
            isVisible: () => document.visibilityState === 'visible',
        });
        this.heartbeat.start();
    }

    /**
     * The rounds to beat for: the viewer's own (a token), running now - or with a tap still on the phone.
     */
    heartbeatRounds() {
        if (this.live === null) {
            return [];
        }

        const nowMs = this.live.serverNow();

        return roundsOf(this.live.state)
            .filter((round) => round.canPropose === true && typeof round.heartbeat === 'string' && round.heartbeat !== '')
            .filter((round) => roundView(round, nowMs).phase === 'running'
                || this.outbox?.itemOf(round.roundId)?.action === 'propose'
                || this.confirming.has(round.roundId.toLowerCase()))
            .map((round) => ({ roundId: round.roundId, token: round.heartbeat }));
    }

    stopProposals() {
        if (this.onTap) {
            this.element.removeEventListener('click', this.onTap);
            window.removeEventListener('online', this.onOnline);
            window.removeEventListener('beforeunload', this.onBeforeUnload);
            this.onTap = null;
        }

        this.heartbeat?.close();
        this.heartbeat = null;
        this.outbox?.close();
        this.outbox = null;
    }

    tap(event) {
        // The moment first - before anything else happens (the competitor finished NOW)
        const tappedAtMs = this.tapNowMs();
        const button = event.target instanceof Element ? event.target.closest('[data-competitor-propose]') : null;

        if (button === null || this.live === null) {
            return;
        }

        const card = button.closest('[data-competitor-round]');
        const roundId = card?.dataset.competitorRound?.toLowerCase();
        const round = roundsOf(this.live.state).find((candidate) => candidate.roundId.toLowerCase() === roundId);

        if (!round || round.canPropose !== true) {
            return;
        }

        event.preventDefault();
        const action = button.dataset.competitorPropose;

        if (action === 'stop') {
            this.answers.delete(roundId);
            // What the tap's time rests on, at the tap (T2) - and whether the browser knew it was offline
            this.confirming.set(roundId, { tappedAtMs, sample: this.live.clockSample(), offline: navigator.onLine === false });
        } else if (action === 'cancel') {
            this.confirming.delete(roundId);
        } else if (action === 'confirm') {
            const tap = this.confirming.get(roundId);
            this.confirming.delete(roundId);

            if (tap && this.outbox !== null && typeof round.entry?.entry === 'string') {
                this.outbox.propose({
                    roundId: round.roundId,
                    entry: round.entry.entry,
                    tappedAtMs: tap.tappedAtMs,
                    offsetMs: tap.sample.offsetMs,
                    rttMs: tap.sample.rttMs,
                    offsetSampleAgeMs: tap.sample.sampleAgeMs,
                    offline: tap.offline,
                    stateHash: round.stopwatch?.stateHash ?? null,
                }).catch(() => {});
            }
        } else if (action === 'undo' && this.outbox !== null) {
            const item = this.outbox.itemOf(round.roundId);
            const proposal = currentProposal(round.proposal ?? null, this.answers.get(roundId)?.proposal ?? null);
            const id = item?.action === 'propose' ? item.id : (proposal?.status === 'pending' ? proposal.id : null);

            if (id !== null) {
                this.outbox.withdraw(id, round.roundId).catch(() => {});
            }
        }

        this.drawProposals();
    }

    drawProposals() {
        if (this.live !== null) {
            this.renderProposals(this.live.state, this.live.serverNow());
        }
    }

    renderProposals(state, nowMs) {
        if (this.live === null) {
            return;
        }

        const panels = [];

        for (const round of roundsOf(state)) {
            const card = this.element.querySelector(`[data-competitor-round="${CSS.escape(round.roundId)}"]`);

            if (card === null) {
                continue;
            }

            // A guest's "Sign in to propose your time": before the round starts only
            const hint = card.querySelector('[data-competitor-signin-hint]');

            if (hint !== null) {
                const phase = roundView(round, nowMs).phase;
                hint.hidden = !(phase === 'idle' || phase === 'scheduled');
            }

            if (this.outbox === null && round.canPropose !== true) {
                continue;
            }

            const id = round.roundId.toLowerCase();
            const item = this.outbox?.itemOf(round.roundId) ?? null;
            const panel = this.outbox === null ? NONE : proposalPanel({
                round,
                nowMs,
                item,
                blocked: this.outbox.blocked,
                answer: this.answers.get(id) ?? null,
                confirming: this.confirming.get(id) ?? null,
            });
            panels.push(panel);

            const slot = card.querySelector('[data-competitor-round-actions]');

            if (slot !== null && this.slotKeys.get(slot) !== panel.key) {
                this.slotKeys.set(slot, panel.key);
                this.drawSlot(slot, panel);
            }
        }

        this.heartbeat?.update();
        const waiting = waitsForJudge(panels);

        if (waiting !== this.waiting) {
            this.waiting = waiting;
            this.live.replan();
        }
    }

    proposalLabel(key, values = {}) {
        let text = (this.proposalLabelsValue ?? {})[key] ?? '';

        for (const [name, seconds] of Object.entries(values)) {
            const parts = clockParts(seconds * 1000);
            text = text.replace(`%${name}%`, `${parts.hours}:${parts.minutes}:${parts.seconds}`);
        }

        return text;
    }

    drawSlot(slot, panel) {
        slot.replaceChildren();
        slot.dataset.competitorProposalState = panel.mode;

        if (panel.mode === 'none') {
            delete slot.dataset.competitorProposalState;

            return;
        }

        const button = (action, text, classes) => {
            const element = document.createElement('button');
            element.type = 'button';
            element.className = classes;
            element.dataset.competitorPropose = action;
            element.textContent = text;

            return element;
        };

        if (panel.confirm !== null) {
            const sheet = document.createElement('div');
            sheet.className = 'competitor-propose-sheet';
            sheet.setAttribute('role', 'alertdialog');
            const question = document.createElement('p');
            question.className = 'competitor-propose-question';
            question.textContent = this.proposalLabel('confirm_question', { time: panel.confirm.seconds });
            const help = document.createElement('p');
            help.className = 'small text-muted mb-2';
            help.textContent = this.proposalLabel('confirm_help');
            const actions = document.createElement('div');
            actions.className = 'competitor-propose-sheet-actions';
            actions.append(
                button('cancel', this.proposalLabel('cancel'), 'btn btn-outline-secondary btn-lg'),
                button('confirm', this.proposalLabel('confirm'), 'btn btn-success btn-lg'),
            );
            sheet.append(question, help, actions);
            slot.append(sheet);
            sheet.querySelector('[data-competitor-propose="confirm"]')?.focus({ preventScroll: true });

            return;
        }

        if (panel.status !== null) {
            const status = document.createElement('p');
            status.className = `competitor-propose-status is-${panel.mode}`;
            status.setAttribute('role', 'status');
            status.dataset.competitorProposalStatus = panel.mode;
            const values = {};

            if (typeof panel.status.time === 'number') {
                values.time = panel.status.time;
            }

            if (typeof panel.status.proposed === 'number') {
                values.proposed = panel.status.proposed;
            }

            status.textContent = panel.mode === 'refused'
                ? panel.status.message
                : this.proposalLabel(panel.status.label, values);

            if (panel.mode === 'rejected') {
                status.textContent += ` ${this.proposalLabel(`reason_${panel.status.reason}`) || this.proposalLabel('reason_other')}`;
            }

            slot.append(status);
        }

        if (panel.undo) {
            slot.append(button('undo', this.proposalLabel('undo'), 'btn btn-link btn-sm competitor-propose-undo'));
        }

        if (panel.button) {
            slot.append(button('stop', this.proposalLabel('stop'), 'btn btn-success btn-lg competitor-propose-stop'));
        }
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

        this.renderProposals(state, nowMs);
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
