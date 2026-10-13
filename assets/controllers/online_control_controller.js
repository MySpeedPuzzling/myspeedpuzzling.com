/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';
import { createClock, fetchServerTime } from '../online/clock_sync.js';
import { adoptRoundClock, adoptState, deriveView, formatClock } from '../online/round_state.js';
import { agoParts, countsOf, matchesFilter, participantView, photoChanged } from '../online/review_control.js';
import { OfficialResultsEvents } from '../official_results_events.js';
import { officialResultsRequest } from '../official_results_api.js';
import { chooseTranslation } from '../translation_choice.js';

// online_live while the page is shown
const POLL_MS = 2500;
// The clock and the "seen … ago" lines
const RENDER_MS = 250;
const GRID_RENDER_MS = 1000;
// No answer for this long: the page says so
const STALE_AFTER_MS = 10000;
// Polls that failed in a row before the next one waits longer (5 s, 10 s, 20 s, at most 30 s)
const BACKOFF_MS = [5000, 10000, 20000, 30000];

/**
 * The control room of an online round (templates/online_rounds/control.html.twig, docs/features/online-competitions/
 * implementation-plan.md WP 1.7):
 *
 * - the round's clock: the server's time (clock_sync.js - `/-/time` and every poll's answer), the round's stopwatch
 *   topic (official_results_events.js with the state's subscription - a start, a cancel, an end arrive at once) and
 *   the polls; the newest clock wins by asOfMs (round_state.js adoptState / adoptRoundClock), the screen comes from
 *   deriveView() exactly as on the phones;
 * - the actions are the round stopwatch's own routes (schedule, cancel, stop) asked for JSON: their answer's stopwatch is
 *   taken at once, then the state is read again; every refusal is said on the page. "End now" asks first, in the page;
 * - online_live every POLL_MS while the page is shown (none while hidden; back on the page = at once): the grid, the
 *   counts and the filters (review_control.js). A photo changes only when there is another one - every answer signs its
 *   URL again, so the URL itself always differs.
 */
export default class extends Controller {
    static targets = ['initialState', 'statusBadge', 'clock', 'clockLine', 'startAt', 'notice', 'presence', 'counts', 'updated', 'grid', 'empty', 'endDialog', 'actionButton'];

    static values = {
        urls: Object,
        csrfToken: String,
        timezone: String,
        locale: String,
        texts: Object,
    };

    connect() {
        this.state = JSON.parse(this.initialStateTarget.textContent);
        this.filter = 'all';
        this.tiles = new Map();
        this.busy = false;
        this.polling = null;
        this.failures = 0;
        this.lastAnswerAt = Date.now();
        this.connection = 'ok';
        this.closed = false;
        this.lastGridRender = 0;
        this.shownScreen = null;

        this.clock = createClock({
            fetchTime: () => fetchServerTime(this.urlsValue.time),
            onChange: () => this.renderClock(),
        });
        this.clock.sync();

        this.events = new OfficialResultsEvents({
            subscription: this.state.mercure ?? null,
            refresh: () => this.poll(),
            onMessage: (data) => this.received(data),
        });
        this.events.start();

        this.onVisibility = () => {
            if (document.visibilityState === 'visible') {
                this.clock.check();
                this.poll();
            }
        };
        document.addEventListener('visibilitychange', this.onVisibility);

        this.scheduleNextPoll(POLL_MS);
        this.renderTimer = window.setInterval(() => this.tick(), RENDER_MS);
        this.render();
    }

    disconnect() {
        this.closed = true;
        window.clearInterval(this.renderTimer);
        window.clearTimeout(this.pollTimer);
        document.removeEventListener('visibilitychange', this.onVisibility);
        this.events.close();
        this.clock.close();
        this.modal()?.hide();
    }

    // ---------------------------------------------------------------- texts

    t(key, params = {}) {
        let text = this.textsValue[key];

        if (typeof text !== 'string') {
            return key;
        }

        for (const [name, value] of Object.entries(params)) {
            text = text.replaceAll(`%${name}%`, String(value));
        }

        return text;
    }

    tc(key, count, params = {}) {
        const message = this.textsValue[key];
        let text = message && typeof message === 'object' ? chooseTranslation(message.message, count, message.locale) : null;

        if (text === null) {
            text = typeof message === 'string' ? message : String(count);
        }

        for (const [name, value] of Object.entries({ count, ...params })) {
            text = text.replaceAll(`%${name}%`, String(value));
        }

        return text;
    }

    ago(ms) {
        const parts = agoParts(ms);

        return parts === null ? '' : this.t(`unit_${parts.unit}`, { count: parts.value });
    }

    // ---------------------------------------------------------------- the state

    scheduleNextPoll(ms) {
        window.clearTimeout(this.pollTimer);

        if (!this.closed) {
            this.pollTimer = window.setTimeout(() => {
                if (document.visibilityState === 'visible') {
                    this.poll();
                } else {
                    this.scheduleNextPoll(POLL_MS);
                }
            }, ms);
        }
    }

    /**
     * online_live once - one request at a time. Resolves to the answer's kind (OfficialResultsEvents' refresh contract).
     */
    poll() {
        if (this.closed) {
            return Promise.resolve('ok');
        }

        if (this.polling !== null) {
            return this.polling;
        }

        const t0 = performance.now();

        this.polling = officialResultsRequest(this.urlsValue.live).then((answer) => {
            const t1 = performance.now();

            if (answer.kind === 'ok' && answer.data && typeof answer.data === 'object') {
                if (Number.isFinite(answer.data.serverNowMs)) {
                    this.clock.observe({ t0, t1, serverNowMs: answer.data.serverNowMs });
                }

                this.adopt(answer.data);
                this.failures = 0;
                this.lastAnswerAt = Date.now();
                this.connection = 'ok';
            } else if (answer.kind === 'auth') {
                this.connection = 'sign_in';
            } else if (answer.kind === 'forbidden') {
                this.connection = 'forbidden';
            } else if (answer.kind === 'client' && answer.status === 404) {
                this.connection = 'gone';
            } else {
                this.failures += 1;
            }

            this.render();

            return answer.kind;
        }).finally(() => {
            this.polling = null;
            const wait = this.failures === 0 ? POLL_MS : BACKOFF_MS[Math.min(this.failures, BACKOFF_MS.length) - 1];
            this.scheduleNextPoll(this.connection === 'ok' ? wait : BACKOFF_MS[BACKOFF_MS.length - 1]);
        });

        return this.polling;
    }

    adopt(incoming) {
        const next = adoptState(this.state, incoming);

        if (next !== this.state) {
            this.state = next;
        }

        if (incoming.mercure) {
            this.events.update(incoming.mercure);
        }
    }

    received(data) {
        if (this.closed || data === null || typeof data !== 'object') {
            return;
        }

        const next = adoptRoundClock(this.state, data);

        if (next !== this.state) {
            this.state = next;
            this.renderClock();
        }

        // The phones react to the clock - their screens and taps follow on the next answers
        this.poll();
    }

    // ---------------------------------------------------------------- the actions

    startInTen() {
        this.act(this.urlsValue.schedule, { countdown: '10' }, 'done_scheduled');
    }

    scheduleAt(event) {
        event.preventDefault();
        this.act(this.urlsValue.schedule, { at: this.startAtTarget.value }, 'done_scheduled');
    }

    cancelStart() {
        this.act(this.urlsValue.cancel, {}, 'done_cancelled');
    }

    askToEnd() {
        this.modal()?.show();
    }

    endNow() {
        this.modal()?.hide();
        this.act(this.urlsValue.stop, {}, 'done_ended');
    }

    /**
     * One stopwatch action: a form POST of the stopwatch's routes asked for JSON (AbstractRoundStopwatchController) -
     * `{stopwatch}` taken at once, `{error}` said on the page; then the state again.
     */
    async act(url, fields, doneKey) {
        if (this.busy) {
            return;
        }

        this.busy = true;
        this.renderBusy();

        let answer = null;

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { Accept: 'application/json' },
                body: new URLSearchParams({ _token: this.csrfTokenValue, ...fields }),
                credentials: 'same-origin',
                redirect: 'manual',
                cache: 'no-store',
            });

            if (response.type === 'opaqueredirect' || (response.status >= 300 && response.status < 400) || response.status === 401) {
                answer = { error: 'sign_in' };
            } else {
                let data = null;

                try {
                    data = await response.json();
                } catch (e) {
                    data = null;
                }

                answer = response.ok && data && typeof data === 'object' && data.stopwatch
                    ? { stopwatch: data.stopwatch }
                    : { error: typeof data?.error === 'string' ? data.error : (response.status === 403 ? 'forbidden' : 'failed') };
            }
        } catch (e) {
            answer = { error: 'offline' };
        }

        this.busy = false;

        if (answer.stopwatch) {
            this.state = adoptRoundClock(this.state, answer.stopwatch);
            this.notify(this.t(doneKey), 'success');
        } else {
            this.notify(this.refusalText(answer.error), 'danger');
        }

        this.render();
        this.poll();
    }

    refusalText(code) {
        if (typeof this.textsValue[`refused_${code}`] === 'string') {
            return this.t(`refused_${code}`);
        }

        return this.t(typeof this.textsValue[`error_${code}`] === 'string' ? `error_${code}` : 'error_failed');
    }

    notify(text, kind) {
        const notice = this.noticeTarget;
        notice.textContent = text;
        notice.className = `alert mt-3 mb-0 py-2 alert-${kind}`;
        notice.hidden = false;
        window.clearTimeout(this.noticeTimer);

        if (kind === 'success') {
            this.noticeTimer = window.setTimeout(() => {
                notice.hidden = true;
            }, 6000);
        }
    }

    modal() {
        return this.hasEndDialogTarget ? Modal.getOrCreateInstance(this.endDialogTarget) : null;
    }

    changeFilter(event) {
        this.filter = event.target.value;
        this.renderGrid();
    }

    // ---------------------------------------------------------------- rendering

    tick() {
        this.renderClock();

        if (Date.now() - this.lastGridRender >= GRID_RENDER_MS) {
            this.renderGrid();
            this.renderStatus();
        }
    }

    render() {
        this.renderClock();
        this.renderGrid();
        this.renderStatus();
        this.renderBusy();
    }

    view() {
        return deriveView(this.state, this.clock.serverNow());
    }

    renderClock() {
        if (this.closed) {
            return;
        }

        const view = this.view();
        const screen = view.screen;

        if (screen !== this.shownScreen) {
            this.shownScreen = screen;
            this.statusBadgeTarget.textContent = this.t(`status_${screen}`);
            this.statusBadgeTarget.className = `badge ${{
                waiting: 'text-bg-secondary',
                scheduled: 'text-bg-primary',
                countdown: 'text-bg-primary',
                running: 'text-bg-success',
                ended: 'text-bg-dark',
            }[screen] ?? 'text-bg-secondary'}`;

            for (const element of this.element.querySelectorAll('[data-online-control-when]')) {
                element.hidden = !element.dataset.onlineControlWhen.split(' ').includes(screen);
            }
        }

        let clock = formatClock(view.elapsedMs);
        // A round without a time limit has no limit line - and a limit never reads as 0 minutes
        let line = view.limitMs === null ? '' : this.tc('limit', Math.max(1, Math.round(view.limitMs / 60000)));

        if (screen === 'scheduled' || screen === 'countdown') {
            clock = formatClock(view.countdownMs, { ceil: true });
            line = `${this.t('starts_in', { time: formatClock(view.countdownMs, { ceil: true }) })} · ${this.t('starts_at', {
                time: this.zoned(view.startedAtMs),
                zone: this.timezoneValue,
            })}`;
        } else if (screen === 'running') {
            line = view.remainingMs === null ? '' : this.t('left', { time: formatClock(view.remainingMs, { ceil: true }) });
        } else if (screen === 'ended') {
            const time = { time: formatClock(view.elapsedMs) };
            line = view.endedBy === 'stop' ? this.t('ended_by_stop', time) : this.t('ended_by_limit', time);
        }

        if (this.clockTarget.textContent !== clock) {
            this.clockTarget.textContent = clock;
        }

        if (this.clockLineTarget.textContent !== line) {
            this.clockLineTarget.textContent = line;
        }
    }

    zoned(ms) {
        try {
            return new Intl.DateTimeFormat(this.localeValue || undefined, {
                timeZone: this.timezoneValue || undefined,
                dateStyle: 'medium',
                timeStyle: 'medium',
            }).format(new Date(ms));
        } catch (e) {
            return new Date(ms).toISOString();
        }
    }

    renderBusy() {
        for (const button of this.actionButtonTargets) {
            button.disabled = this.busy;
        }
    }

    renderStatus() {
        if (this.closed) {
            return;
        }

        let text = '';

        if (this.connection !== 'ok') {
            text = this.t(`status_${this.connection}`);
        } else {
            const since = Date.now() - this.lastAnswerAt;
            text = since > STALE_AFTER_MS ? this.t('stale', { time: this.ago(since) }) : this.t('updated', { time: this.ago(since) });
        }

        if (this.updatedTarget.textContent !== text) {
            this.updatedTarget.textContent = text;
        }

        this.presenceTarget.hidden = this.state.presenceAvailable !== false;
    }

    renderGrid() {
        if (this.closed) {
            return;
        }

        this.lastGridRender = Date.now();
        const serverNowMs = this.clock.serverNow();
        const round = this.view();
        const participants = Array.isArray(this.state.participants) ? this.state.participants : [];
        const views = participants.map((participant) => ({
            participant,
            view: participantView(participant, { serverNowMs, startedAtMs: round.startedAtMs, endedAtMs: round.endedAtMs }),
        }));

        this.renderCounts(countsOf(views.map((item) => item.view)));

        const shown = views.filter((item) => matchesFilter(item.view, this.filter));
        const seen = new Set();
        let previous = null;

        for (const { participant, view } of shown) {
            const id = participant.participantId;
            seen.add(id);
            let tile = this.tiles.get(id);

            if (!tile) {
                tile = this.createTile(participant);
                this.tiles.set(id, tile);
            }

            this.updateTile(tile, participant, view);

            // In the answer's order (by name) - moved only when out of place
            const expected = previous === null ? this.gridTarget.firstElementChild : previous.element.nextElementSibling;

            if (expected !== tile.element) {
                this.gridTarget.insertBefore(tile.element, expected);
            }

            previous = tile;
        }

        for (const [id, tile] of this.tiles) {
            if (!seen.has(id)) {
                tile.element.remove();
                this.tiles.delete(id);
            }
        }

        const empty = shown.length === 0;
        this.emptyTarget.hidden = !empty;

        if (empty) {
            this.emptyTarget.textContent = participants.length === 0 ? this.t('empty') : this.t('empty_filter');
        }
    }

    renderCounts(counts) {
        const items = [
            this.tc('count_entered', counts.entered),
            this.t('count_seen', { count: counts.seen }),
            this.t('count_ready', { count: counts.ready }),
            this.t('count_online', { count: counts.online }),
            this.t('count_camera_off', { count: counts.cameraOff }),
            this.t('count_finished', { count: counts.finished }),
            this.t('count_reported', { count: counts.reported }),
        ];
        const key = items.join('|');

        if (this.countsKey === key) {
            return;
        }

        this.countsKey = key;
        this.countsTarget.replaceChildren(...items.map((text, index) => {
            const item = document.createElement('li');
            item.className = `list-inline-item online-control-count${index === 0 ? ' fw-semibold' : ''}`;
            item.textContent = text;

            return item;
        }));
    }

    createTile(participant) {
        const element = document.createElement('article');
        element.className = 'online-tile';
        element.dataset.participantId = participant.participantId;

        const photo = document.createElement('div');
        photo.className = 'online-tile-photo';
        const image = document.createElement('img');
        image.alt = this.t('tile_photo_alt', { name: participant.name });
        image.decoding = 'async';
        image.hidden = true;
        const placeholder = document.createElement('span');
        placeholder.className = 'online-tile-no-photo';
        placeholder.textContent = this.t('tile_no_photo');
        photo.append(image, placeholder);

        const body = document.createElement('div');
        body.className = 'online-tile-body';
        const name = document.createElement('h3');
        name.className = 'online-tile-name h6';
        const chips = document.createElement('ul');
        chips.className = 'online-tile-chips list-unstyled';
        const finish = document.createElement('p');
        finish.className = 'online-tile-finish';
        body.append(name, chips, finish);
        element.append(photo, body);

        const tile = { element, image, placeholder, name, chips, finish, photo: null, key: null };

        image.addEventListener('load', () => {
            image.hidden = false;
            placeholder.hidden = true;
        });
        image.addEventListener('error', () => {
            if (tile.photo) {
                tile.photo.failed = true;
            }
        });

        return tile;
    }

    updateTile(tile, participant, view) {
        if (photoChanged(tile.photo, participant.photo)) {
            tile.photo = { mediaId: participant.photo.mediaId, failed: false };
            tile.image.src = participant.photo.url;
        }

        const chips = [];
        chips.push([{ ready: 'ok', waiting: 'warn', not_seen: 'bad' }[view.readiness], this.t(`tile_${view.readiness}`)]);

        if (view.online === true) {
            chips.push(['ok', `${this.t('tile_online')} · ${this.t('tile_last_seen', { time: this.ago(view.lastSeenAgoMs) })}`]);
        } else if (view.online === false) {
            chips.push(['bad', `${this.t('tile_offline')} · ${this.t('tile_last_seen', { time: this.ago(view.lastSeenAgoMs) })}`]);
        }

        if (view.camera !== 'unknown') {
            chips.push(view.camera === 'ok' ? ['ok', this.t('tile_camera_ok')] : ['bad', this.t('tile_camera_off')]);
        }

        if (view.hidden) {
            chips.push(['bad', this.t('tile_page_hidden')]);
        }

        if (view.clockQuality !== 'unknown') {
            chips.push([
                { good: 'ok', fair: 'warn', poor: 'bad' }[view.clockQuality],
                view.clockQuality === 'poor' ? this.t('tile_clock_poor', { ms: view.clockErrorMs }) : this.t('tile_clock', { ms: view.clockErrorMs }),
            ]);
        }

        if (view.pendingUploads > 0) {
            chips.push(['warn', this.tc('tile_uploads_pending', view.pendingUploads)]);
        }

        if (view.confirmed) {
            chips.push(['ok', this.t('tile_confirmed')]);
        }

        let finish = '';
        let finishKind = '';

        if (view.finished) {
            finish = view.finishAfterEnd
                ? this.t('tile_finished_after_end')
                : this.t('tile_finished', { time: view.finishElapsedMs === null ? '' : formatClock(view.finishElapsedMs) });
            finishKind = view.finishAfterEnd ? 'warn' : 'ok';
        } else if (view.reported) {
            finish = view.reported.measure === 'placed'
                ? this.tc('tile_reported_placed', view.reported.pieces)
                : this.tc('tile_reported_left', view.reported.pieces);
            finishKind = 'info';
        }

        const key = JSON.stringify([participant.name, participant.country, chips, finish, finishKind]);

        if (tile.key === key) {
            return;
        }

        tile.key = key;
        tile.element.dataset.readiness = view.readiness;
        tile.element.dataset.finished = view.finished ? 'true' : 'false';

        const name = document.createElement('span');
        name.textContent = participant.name;
        const nodes = [];

        if (typeof participant.country === 'string' && /^[a-z]{2}$/.test(participant.country)) {
            const flag = document.createElement('span');
            flag.className = `fi fi-${participant.country} shadow-custom me-1`;
            flag.setAttribute('aria-hidden', 'true');
            nodes.push(flag);
        }

        nodes.push(name);
        tile.name.replaceChildren(...nodes);
        tile.chips.replaceChildren(...chips.map(([kind, text]) => {
            const chip = document.createElement('li');
            chip.className = `online-chip online-chip--${kind}`;
            chip.textContent = text;

            return chip;
        }));
        tile.finish.textContent = finish;
        tile.finish.className = `online-tile-finish${finishKind ? ` online-tile-finish--${finishKind}` : ''}`;
        tile.finish.hidden = finish === '';
    }
}
