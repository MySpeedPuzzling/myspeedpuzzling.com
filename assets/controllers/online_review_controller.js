/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { formatClock } from '../online/round_state.js';
import {
    formatAdjustment,
    formatElapsed,
    frameSlices,
    momentConfirmation,
    nearestFrame,
    nextToReview,
    piecesConfirmation,
    piecesPlaced,
    resultSeconds,
    reviewKey,
    stepIndex,
} from '../online/review_logic.js';
import { OfficialResultsEvents } from '../official_results_events.js';
import { officialResultsRequest } from '../official_results_api.js';
import { chooseTranslation } from '../translation_choice.js';

// The list is read again this often while the page is shown - new taps arrive while the round runs
const LIST_REFRESH_MS = 30000;
// Several saves in a moment (another reviewer, the results desk) - one read of the list
const LIVE_REFRESH_DELAY_MS = 800;
// Decoded frames kept per burst, bursts kept in memory
const BITMAPS_KEPT = 24;
const BURSTS_KEPT = 4;
const STATUS_BADGES = {
    needs_look: 'text-bg-warning',
    confirmed: 'text-bg-success',
    unfinished: 'text-bg-info',
    no_finish: 'text-bg-secondary',
};
// Shown with their tap, not in the timeline
const TAP_MEDIA = ['button', 'burst'];

/**
 * The review of an online round (templates/online_rounds/review.html.twig, docs/features/online-competitions/README.md
 * §5.9) - keyboard first, so one person gets through a round quickly:
 *
 * - the list (OnlineReview::list(), read again every LIST_REFRESH_MS and after another device's save on the round's
 *   private results topic): ↑/↓ choose a participant; their evidence (online_review_entry) loads at once;
 * - a Finished tap opens its burst: the one object is fetched once through its short-lived URL, its frames sliced by
 *   the manifest (review_logic.js frameSlices()) and decoded on demand (createImageBitmap); ←/→ or the slider step
 *   through them, every frame's time on the round's clock - the moment becomes the frame's;
 * - C confirms the finish (the tap's own moment = source `button`, a frame = `reviewer`), U confirms unfinished with the
 *   pieces placed (online_review_confirm); the answer replaces the row, the next participant to look at follows. A
 *   refusal is shown with its reason - never silently.
 */
export default class extends Controller {
    static targets = [
        'initialState', 'progress', 'notice', 'list', 'listEmpty', 'detail', 'detailPlaceholder', 'detailLoading', 'detailError',
        'detailBody', 'detailName', 'detailOfficial', 'detailStatus', 'detailFlags', 'notConnected', 'finishPanel', 'taps',
        'evidence', 'canvas', 'burstStatus', 'slider', 'frameButton', 'frameLabel', 'tapPhotoLink', 'tapPhoto',
        'tapPhotoPending', 'momentBox', 'momentTime', 'momentLine', 'confirmFinishButton', 'unfinishedPanel', 'reported',
        'pieces', 'piecesOf', 'piecesError', 'confirmUnfinishedButton', 'endPhotoBox', 'endPhotoLink', 'endPhoto', 'timeline',
    ];

    static values = {
        urls: Object,
        placeholder: String,
        selected: String,
        csrfToken: String,
        texts: Object,
    };

    connect() {
        this.list = JSON.parse(this.initialStateTarget.textContent);
        this.filter = 'all';
        this.selectedId = null;
        this.detail = null;
        this.detailRequest = 0;
        // The official result the selected participant's evidence showed when it was opened (wire format)
        this.seenResult = null;
        this.tapId = null;
        this.momentMs = null;
        this.burst = null;
        this.frameIndex = -1;
        this.burstCache = new Map();
        this.busy = false;
        this.closed = false;
        this.listFetch = null;

        this.onKeydown = (event) => this.keydown(event);
        document.addEventListener('keydown', this.onKeydown);
        this.onVisibility = () => {
            if (document.visibilityState === 'visible') {
                this.refreshList();
            }
        };
        document.addEventListener('visibilitychange', this.onVisibility);

        this.events = new OfficialResultsEvents({
            subscription: this.list.mercure ?? null,
            refresh: () => this.refreshList(),
            onMessage: (data) => this.liveUpdate(data),
        });
        this.events.start();
        this.listTimer = window.setInterval(() => {
            if (document.visibilityState === 'visible') {
                this.refreshList();
            }
        }, LIST_REFRESH_MS);

        this.renderList();

        const wanted = this.selectedValue !== '' && this.entry(this.selectedValue) !== null ? this.selectedValue : null;
        const first = wanted ?? this.rows()[Math.max(0, nextToReview(this.rows(), -1))]?.participantId ?? null;

        if (first !== null) {
            this.select(first);
        }
    }

    disconnect() {
        this.closed = true;
        document.removeEventListener('keydown', this.onKeydown);
        document.removeEventListener('visibilitychange', this.onVisibility);
        window.clearInterval(this.listTimer);
        window.clearTimeout(this.liveTimer);
        this.events.close();

        for (const burst of this.burstCache.values()) {
            closeBitmaps(burst);
        }

        this.burstCache.clear();
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

    resultText(result) {
        if (result === null || typeof result !== 'object') {
            return null;
        }

        if (Number.isInteger(result.seconds)) {
            return formatClock(result.seconds * 1000);
        }

        if (Number.isInteger(result.piecesPlaced)) {
            return this.tc('result_pieces', result.piecesPlaced);
        }

        return result.didNotStart === true ? this.t('result_did_not_start') : null;
    }

    flagText(flag) {
        const key = `flag_${flag.code}`;

        if (this.textsValue[key] === undefined) {
            return flag.code;
        }

        const params = { count: flag.count ?? 0, seconds: flag.seconds ?? 0 };

        return typeof this.textsValue[key] === 'object' ? this.tc(key, flag.count ?? 1, params) : this.t(key, params);
    }

    // ---------------------------------------------------------------- the list

    rows() {
        const entries = Array.isArray(this.list.entries) ? this.list.entries : [];

        return this.filter === 'all' ? entries : entries.filter((entry) => entry.status === this.filter);
    }

    entry(participantId) {
        return (this.list.entries ?? []).find((entry) => entry.participantId === participantId) ?? null;
    }

    startedAtMs() {
        return this.detail?.round?.startedAtMs ?? this.list.round?.startedAtMs ?? null;
    }

    changeFilter(event) {
        this.filter = event.target.value;
        this.renderList();
    }

    renderList() {
        const entries = Array.isArray(this.list.entries) ? this.list.entries : [];
        const rows = this.rows();
        const focused = document.activeElement?.closest?.('[data-participant-id]')?.dataset.participantId ?? null;

        this.progressTarget.textContent = this.t('progress', {
            confirmed: entries.filter((entry) => entry.status === 'confirmed').length,
            total: entries.length,
        });

        this.listTarget.replaceChildren(...rows.map((entry) => this.rowElement(entry)));
        this.listEmptyTarget.hidden = rows.length > 0;
        this.listEmptyTarget.textContent = entries.length === 0 ? this.t('empty') : this.t('empty_filter');

        if (focused !== null) {
            this.listTarget.querySelector(`[data-participant-id="${CSS.escape(focused)}"]`)?.focus();
        }
    }

    rowElement(entry) {
        const item = document.createElement('li');
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'online-review-row';
        button.dataset.participantId = entry.participantId;
        button.dataset.status = entry.status;
        button.addEventListener('click', () => this.select(entry.participantId));

        if (entry.participantId === this.selectedId) {
            button.classList.add('is-selected');
            button.setAttribute('aria-current', 'true');
        }

        const name = document.createElement('span');
        name.className = 'online-review-row-name';
        name.append(...flagNodes(entry.country), document.createTextNode(entry.name));

        const time = document.createElement('span');
        time.className = 'online-review-row-time font-monospace';
        const official = this.resultText(entry.official);

        if (official !== null) {
            time.textContent = official;
            time.classList.add('fw-semibold');
        } else if (entry.suggestion?.kind === 'finished' && Number.isInteger(entry.suggestion.seconds)) {
            time.textContent = formatClock(entry.suggestion.seconds * 1000);
            time.classList.add('text-body-secondary');
        } else if (entry.suggestion?.kind === 'unfinished' && Number.isInteger(entry.suggestion.piecesPlaced)) {
            time.textContent = this.tc('result_pieces', entry.suggestion.piecesPlaced);
            time.classList.add('text-body-secondary');
        }

        const status = document.createElement('span');
        status.className = `badge ${STATUS_BADGES[entry.status] ?? 'text-bg-secondary'}`;
        status.textContent = this.t(`status_${entry.status}`);

        button.append(name, time, status);

        if (Array.isArray(entry.flags) && entry.flags.length > 0) {
            const flags = document.createElement('span');
            flags.className = 'online-review-row-flags text-warning';
            flags.title = entry.flags.map((flag) => this.flagText(flag)).join('\n');
            const icon = document.createElement('i');
            icon.className = 'bi bi-flag-fill me-1';
            icon.setAttribute('aria-hidden', 'true');
            const label = document.createElement('span');
            label.className = 'visually-hidden';
            label.textContent = flags.title;
            flags.append(icon, document.createTextNode(String(entry.flags.length)), label);
            button.append(flags);
        }

        item.append(button);

        return item;
    }

    moveSelection(delta) {
        const rows = this.rows();
        const index = rows.findIndex((entry) => entry.participantId === this.selectedId);
        const next = stepIndex(index, delta, rows.length);

        if (next >= 0 && rows[next].participantId !== this.selectedId) {
            this.select(rows[next].participantId);
            this.listTarget.querySelector(`[data-participant-id="${CSS.escape(rows[next].participantId)}"]`)?.scrollIntoView({ block: 'nearest' });
        }
    }

    select(participantId) {
        if (this.closed) {
            return;
        }

        this.selectedId = participantId;

        for (const button of this.listTarget.querySelectorAll('[data-participant-id]')) {
            const selected = button.dataset.participantId === participantId;
            button.classList.toggle('is-selected', selected);

            if (selected) {
                button.setAttribute('aria-current', 'true');
            } else {
                button.removeAttribute('aria-current');
            }
        }

        try {
            const url = new URL(this.urlsValue.list, window.location.href);
            url.searchParams.set('participant', participantId);
            window.history.replaceState(window.history.state, '', url.toString());
        } catch (e) {
            // The address stays as it is
        }

        this.loadDetail(participantId);
    }

    refreshList() {
        if (this.closed) {
            return Promise.resolve('ok');
        }

        if (this.listFetch !== null) {
            return this.listFetch;
        }

        this.listFetch = officialResultsRequest(this.urlsValue.list).then((answer) => {
            if (answer.kind === 'ok' && answer.data && Array.isArray(answer.data.entries)) {
                const before = this.entry(this.selectedId);
                this.list = answer.data;

                if (answer.data.mercure) {
                    this.events.update(answer.data.mercure);
                }

                this.renderList();
                const after = this.entry(this.selectedId);

                // New taps or a decision from elsewhere - the evidence shown is read again
                if (this.selectedId !== null && after !== null && (before === null || before.taps !== after.taps || before.status !== after.status || JSON.stringify(before.official) !== JSON.stringify(after.official))) {
                    this.loadDetail(this.selectedId, { keepMoment: true });
                }
            }

            return answer.kind;
        }).finally(() => {
            this.listFetch = null;
        });

        return this.listFetch;
    }

    liveUpdate(data) {
        if (data === null || typeof data !== 'object' || (data.roundId && data.roundId !== this.list.round?.id)) {
            return;
        }

        window.clearTimeout(this.liveTimer);
        this.liveTimer = window.setTimeout(() => this.refreshList(), LIVE_REFRESH_DELAY_MS);
    }

    // ---------------------------------------------------------------- one participant

    entryUrl(participantId) {
        return this.urlsValue.entry.replace(this.placeholderValue, participantId);
    }

    confirmUrl(participantId) {
        return this.urlsValue.confirm.replace(this.placeholderValue, participantId);
    }

    reloadDetail() {
        if (this.selectedId !== null) {
            this.loadDetail(this.selectedId);
        }
    }

    async loadDetail(participantId, { keepMoment = false } = {}) {
        const request = ++this.detailRequest;
        const keep = keepMoment && this.detail?.entry?.participantId === participantId ? { tapId: this.tapId, momentMs: this.momentMs } : null;

        if (!keep) {
            this.detailPlaceholderTarget.hidden = true;
            this.detailErrorTarget.hidden = true;
            this.detailLoadingTarget.hidden = false;
        }

        const answer = await officialResultsRequest(this.entryUrl(participantId));

        if (request !== this.detailRequest || this.closed) {
            return;
        }

        this.detailLoadingTarget.hidden = true;

        if (answer.kind !== 'ok' || !answer.data?.entry) {
            if (!keep) {
                this.detail = null;
                this.detailBodyTarget.hidden = true;
                this.detailErrorTarget.hidden = false;
            }

            if (answer.kind === 'auth') {
                this.notify(this.t('error_sign_in'), 'danger');
            } else if (answer.kind === 'client' && answer.status === 404) {
                this.notify(this.t('error_gone'), 'danger');
            } else if (answer.kind === 'forbidden') {
                this.notify(this.t('error_forbidden'), 'danger');
            }

            return;
        }

        this.detail = answer.data;

        // What the reviewer sees now - the `from` of their confirmation. A refresh in the background (keep) leaves it:
        // a result entered meanwhile must come back as a conflict when they confirm, never pass as the one they saw
        if (!keep) {
            this.seenResult = answer.data.entry.official ?? null;
        }

        this.detailBodyTarget.hidden = false;
        this.detailErrorTarget.hidden = true;
        this.renderDetailHeader();

        const taps = this.detail.taps ?? [];
        const entry = this.detail.entry;
        let tapId = null;
        let momentMs = null;

        if (keep && taps.some((tap) => tap.eventId === keep.tapId)) {
            tapId = keep.tapId;
            momentMs = keep.momentMs;
        } else if (entry.confirmed && Number.isFinite(entry.confirmed.momentMs) && taps.length > 0) {
            // What was confirmed - its tap, its moment
            momentMs = entry.confirmed.momentMs;
            tapId = taps.reduce((best, tap) => (Math.abs(tap.momentMs - momentMs) < Math.abs(best.momentMs - momentMs) ? tap : best)).eventId;
        } else if (entry.suggestion?.tapEventId) {
            tapId = entry.suggestion.tapEventId;
        } else if (taps.length > 0) {
            tapId = taps[taps.length - 1].eventId;
        }

        this.renderUnfinished();
        this.renderTimeline();
        this.chooseTap(tapId, { momentMs });
    }

    renderDetailHeader() {
        const entry = this.detail.entry;
        this.detailNameTarget.replaceChildren(...flagNodes(entry.country), document.createTextNode(entry.name));
        this.detailStatusTarget.className = `badge ${STATUS_BADGES[entry.status] ?? 'text-bg-secondary'}`;
        this.detailStatusTarget.textContent = this.t(`status_${entry.status}`);

        const official = this.resultText(entry.official);
        let line = official === null ? this.t('no_result') : this.t('official_now', { result: official });

        if (entry.confirmed?.source) {
            line += ` · ${this.t('confirmed_by', { source: this.t(`source_${entry.confirmed.source}`) })}`;
        }

        this.detailOfficialTarget.textContent = line;
        this.detailFlagsTarget.replaceChildren(...(entry.flags ?? []).map((flag) => {
            const item = document.createElement('li');
            item.className = 'online-chip online-chip--warn';
            item.textContent = this.flagText(flag);

            return item;
        }));

        const connected = entry.onlineEntryId !== null;
        this.notConnectedTarget.hidden = connected;
        this.finishPanelTarget.hidden = !connected;
        this.unfinishedPanelTarget.hidden = !connected;
    }

    chooseTap(tapId, { momentMs = null } = {}) {
        const taps = this.detail?.taps ?? [];
        const tap = taps.find((candidate) => candidate.eventId === tapId) ?? null;
        this.tapId = tap?.eventId ?? null;
        this.momentMs = tap === null ? null : (Number.isFinite(momentMs) ? momentMs : tap.momentMs);
        this.burst = null;
        this.frameIndex = -1;
        this.renderTaps();
        this.evidenceTarget.hidden = tap === null;
        this.momentBoxTarget.hidden = tap === null;

        if (tap === null) {
            this.renderMoment();

            return;
        }

        const media = this.detail.media ?? [];
        const photo = media.filter((item) => item.kind === 'button' && item.relatedEventId === tap.eventId).pop() ?? null;
        this.showPhoto(this.tapPhotoLinkTarget, this.tapPhotoTarget, photo);
        // Registered but not uploaded yet (the phone was offline, a store outage) - said, never hidden
        this.tapPhotoPendingTarget.hidden = !(photo !== null && photo.url === null);

        const burst = media.filter((item) => item.kind === 'burst' && item.relatedEventId === tap.eventId).pop() ?? null;
        this.renderMoment();
        this.openBurst(burst, tap);
    }

    selectTap(event) {
        this.chooseTap(event.currentTarget.dataset.tapId);
    }

    renderTaps() {
        const taps = this.detail?.taps ?? [];
        const start = this.startedAtMs();

        if (taps.length === 0) {
            const none = document.createElement('p');
            none.className = 'small text-body-secondary mb-0';
            none.textContent = this.t('no_taps');
            this.tapsTarget.replaceChildren(none);

            return;
        }

        this.tapsTarget.replaceChildren(...taps.map((tap, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `btn btn-sm ${tap.eventId === this.tapId ? 'btn-primary' : 'btn-outline-primary'}`;
            button.dataset.tapId = tap.eventId;
            button.setAttribute('aria-pressed', tap.eventId === this.tapId ? 'true' : 'false');
            button.addEventListener('click', (event) => this.selectTap(event));
            button.textContent = this.t('tap', {
                number: index + 1,
                time: Number.isFinite(start) ? formatElapsed(tap.momentMs - start) : new Date(tap.momentMs).toLocaleTimeString(),
            });

            const notes = [];

            if (tap.undone) {
                notes.push(this.t('tap_undone'));
            }

            if (tap.position === 'before_start') {
                notes.push(this.t('tap_before_start'));
            } else if (tap.position === 'after_end') {
                notes.push(this.t('tap_after_end'));
            }

            if (tap.heardMeanwhile && tap.lateMs >= 30000) {
                notes.push(this.t('tap_late', { seconds: Math.floor(tap.lateMs / 1000) }));
            }

            if (notes.length > 0) {
                const small = document.createElement('span');
                small.className = 'ms-1 small';
                small.textContent = `(${notes.join(', ')})`;
                button.append(small);
            }

            return button;
        }));
    }

    // ---------------------------------------------------------------- the burst

    async openBurst(media, tap) {
        this.setBurstStatus('');
        this.sliderTarget.max = '0';
        this.sliderTarget.value = '0';
        this.frameLabelTarget.textContent = '';
        this.clearCanvas();

        if (media === null) {
            this.setBurstStatus(this.t('burst_none'));

            return;
        }

        if (media.url === null) {
            this.setBurstStatus(this.t('burst_pending'));

            return;
        }

        this.setBurstStatus(this.t('burst_loading'));
        const tapId = tap.eventId;
        let burst;

        try {
            burst = await this.loadBurst(media);
        } catch (e) {
            if (this.tapId === tapId) {
                this.setBurstStatus(this.t('burst_failed'));
            }

            return;
        }

        if (this.tapId !== tapId || this.closed) {
            return;
        }

        if (burst.frames.length === 0) {
            this.setBurstStatus(this.t('burst_none'));

            return;
        }

        this.burst = burst;
        this.setBurstStatus('');
        this.sliderTarget.max = String(burst.frames.length - 1);
        this.showFrame(nearestFrame(burst.frames, this.momentMs ?? tap.momentMs), { move: false });
    }

    async loadBurst(media) {
        const cached = this.burstCache.get(media.id);

        if (cached) {
            return cached;
        }

        const response = await fetch(media.url, { credentials: 'omit' });

        if (!response.ok) {
            throw new Error(`The burst answered ${response.status}`);
        }

        const blob = await response.blob();
        const burst = { mediaId: media.id, blob, frames: frameSlices(media.manifest, blob.size), bitmaps: new Map() };
        this.burstCache.set(media.id, burst);

        while (this.burstCache.size > BURSTS_KEPT) {
            const [oldest] = this.burstCache.keys();
            closeBitmaps(this.burstCache.get(oldest));
            this.burstCache.delete(oldest);
        }

        return burst;
    }

    previousFrame() {
        this.stepFrame(-1);
    }

    nextFrame() {
        this.stepFrame(1);
    }

    stepFrame(delta) {
        if (this.burst !== null && this.frameIndex >= 0) {
            this.showFrame(stepIndex(this.frameIndex, delta, this.burst.frames.length));
        }
    }

    scrub(event) {
        if (this.burst !== null) {
            this.showFrame(parseInt(event.target.value, 10));
        }
    }

    backToTap() {
        const tap = (this.detail?.taps ?? []).find((candidate) => candidate.eventId === this.tapId);

        if (tap) {
            this.momentMs = tap.momentMs;

            if (this.burst !== null) {
                this.showFrame(nearestFrame(this.burst.frames, tap.momentMs), { move: false });
            }

            this.renderMoment();
        }
    }

    /**
     * Shows a frame - and makes its capture moment the finish moment (`move`), unless it only opens the burst at the tap.
     */
    async showFrame(index, { move = true } = {}) {
        const burst = this.burst;

        if (burst === null || !Number.isInteger(index) || index < 0 || index >= burst.frames.length) {
            return;
        }

        this.frameIndex = index;
        const frame = burst.frames[index];

        if (move) {
            this.momentMs = frame.momentMs;
        }

        this.sliderTarget.value = String(index);
        const start = this.startedAtMs();
        this.frameLabelTarget.textContent = this.t('frame', {
            number: index + 1,
            count: burst.frames.length,
            time: Number.isFinite(start) ? formatElapsed(frame.captureMs - start) : '',
        });
        this.renderMoment();

        let bitmap = burst.bitmaps.get(index);

        if (!bitmap) {
            try {
                bitmap = await decodeFrame(burst.blob.slice(frame.offset, frame.offset + frame.length, 'image/jpeg'));
            } catch (e) {
                this.setBurstStatus(this.t('burst_failed'));

                return;
            }

            burst.bitmaps.set(index, bitmap);

            while (burst.bitmaps.size > BITMAPS_KEPT) {
                const [oldest] = burst.bitmaps.keys();
                burst.bitmaps.get(oldest)?.close?.();
                burst.bitmaps.delete(oldest);
            }
        }

        // Another frame or another tap meanwhile - that one is drawn
        if (this.burst !== burst || this.frameIndex !== index) {
            return;
        }

        const canvas = this.canvasTarget;

        if (canvas.width !== bitmap.width || canvas.height !== bitmap.height) {
            canvas.width = bitmap.width;
            canvas.height = bitmap.height;
        }

        canvas.getContext('2d')?.drawImage(bitmap, 0, 0);
    }

    clearCanvas() {
        const canvas = this.canvasTarget;
        canvas.getContext('2d')?.clearRect(0, 0, canvas.width, canvas.height);
    }

    setBurstStatus(text) {
        this.burstStatusTarget.textContent = text;
        this.burstStatusTarget.hidden = text === '';

        for (const button of this.frameButtonTargets) {
            button.disabled = this.burst === null;
        }

        this.sliderTarget.disabled = this.burst === null;
    }

    renderMoment() {
        const tap = (this.detail?.taps ?? []).find((candidate) => candidate.eventId === this.tapId) ?? null;
        const start = this.startedAtMs();

        if (tap === null || !Number.isFinite(this.momentMs)) {
            this.momentTimeTarget.textContent = '';
            this.momentLineTarget.textContent = '';
            this.renderBusy();

            return;
        }

        this.momentTimeTarget.textContent = Number.isFinite(start) ? formatElapsed(this.momentMs - start) : '';
        const seconds = Number.isFinite(start) ? resultSeconds(this.momentMs, start) : null;
        let line = seconds === null ? '' : this.t('moment_official', { time: formatClock(seconds * 1000) });

        if (Math.round(this.momentMs) !== tap.momentMs) {
            line += `${line === '' ? '' : ' · '}${this.t('moment_moved', { adjustment: formatAdjustment(this.momentMs - tap.momentMs) })}`;
        }

        this.momentLineTarget.textContent = line;
        this.renderBusy();
    }

    // ---------------------------------------------------------------- unfinished

    renderUnfinished() {
        const entry = this.detail.entry;
        const round = this.detail.round ?? {};
        const report = [...(this.detail.events ?? [])].reverse().find((event) => event.type === 'timeup.pieces') ?? null;
        const measure = report?.data?.measure ?? round.unfinishedMeasure ?? 'left';
        const reportedPieces = Number.isInteger(report?.data?.pieces) ? report.data.pieces : null;

        this.reportedTarget.textContent = reportedPieces === null
            ? this.t('reported_none')
            : (measure === 'placed' ? this.t('reported_placed', { pieces: reportedPieces }) : this.t('reported_left', { pieces: reportedPieces }));

        let pieces = null;

        if (Number.isInteger(entry.official?.piecesPlaced)) {
            pieces = entry.official.piecesPlaced;
        } else if (reportedPieces !== null) {
            pieces = piecesPlaced(measure, reportedPieces, round.piecesTotal ?? null);
        }

        this.piecesTarget.value = pieces === null ? '' : String(pieces);
        this.piecesTarget.max = Number.isInteger(round.piecesTotal) ? String(round.piecesTotal - 1) : '';
        this.piecesOfTarget.textContent = Number.isInteger(round.piecesTotal) ? this.t('pieces_of', { total: round.piecesTotal }) : '';
        this.piecesOfTarget.hidden = !Number.isInteger(round.piecesTotal);
        this.piecesErrorTarget.hidden = true;

        const end = (this.detail.media ?? []).filter((item) => item.kind === 'end').pop() ?? null;
        this.endPhotoBoxTarget.hidden = end === null;
        this.showPhoto(this.endPhotoLinkTarget, this.endPhotoTarget, end);
    }

    showPhoto(link, image, media) {
        if (media === null || media.url === null) {
            link.hidden = true;
            image.removeAttribute('src');

            return;
        }

        link.hidden = false;
        link.href = media.url;
        image.src = media.url;
    }

    // ---------------------------------------------------------------- the timeline

    renderTimeline() {
        const start = this.startedAtMs();
        const round = this.detail.round ?? {};
        const items = [];

        if (Number.isFinite(start)) {
            items.push({ atMs: start, label: this.t('timeline_start'), kind: 'mark' });
        }

        if (round.status === 'ended' && Number.isFinite(round.endsAtMs)) {
            items.push({ atMs: round.endsAtMs, label: this.t('timeline_end'), kind: 'mark' });
        }

        for (const event of this.detail.events ?? []) {
            items.push({ atMs: event.atMs, label: this.eventLabel(event), kind: 'event', type: event.type });
        }

        for (const media of this.detail.media ?? []) {
            if (!TAP_MEDIA.includes(media.kind)) {
                items.push({ atMs: media.takenMs, label: this.t(`photo_${media.kind}`), kind: 'photo', media });
            }
        }

        items.sort((a, b) => a.atMs - b.atMs);

        const nodes = [];
        let strip = null;

        for (const item of items) {
            if (item.kind === 'photo' && item.media.kind === 'periodic') {
                if (strip === null) {
                    strip = document.createElement('li');
                    strip.className = 'online-timeline-strip';
                    nodes.push(strip);
                }

                strip.append(this.thumbnail(item, start));

                continue;
            }

            strip = null;
            const node = document.createElement('li');
            node.className = `online-timeline-item online-timeline-item--${item.kind}`;
            const time = document.createElement('span');
            time.className = 'online-timeline-time font-monospace';
            time.textContent = this.timeText(item.atMs, start);
            const label = document.createElement('span');
            label.textContent = item.label;
            node.append(time, label);

            if (item.kind === 'photo') {
                node.append(this.thumbnail(item, start, false));
            }

            nodes.push(node);
        }

        this.timelineTarget.replaceChildren(...nodes);
    }

    eventLabel(event) {
        const key = `event_${String(event.type).replaceAll('.', '_')}`;
        let label = this.textsValue[key] === undefined ? event.type : this.t(key);

        if (event.type === 'timeup.pieces' && Number.isInteger(event.data?.pieces)) {
            label += `: ${event.data.pieces} (${event.data.measure ?? ''})`.replace(' ()', '');
        }

        return label;
    }

    timeText(atMs, start) {
        return Number.isFinite(start) ? formatElapsed(atMs - start) : new Date(atMs).toLocaleTimeString();
    }

    thumbnail(item, start, withTime = true) {
        const figure = document.createElement('figure');
        figure.className = 'online-timeline-photo';

        if (item.media.url === null) {
            const pending = document.createElement('span');
            pending.className = 'online-timeline-pending small text-body-secondary';
            pending.textContent = this.t('pending');
            figure.append(pending);
        } else {
            const link = document.createElement('a');
            link.href = item.media.url;
            link.target = '_blank';
            link.rel = 'noopener';
            const image = document.createElement('img');
            image.src = item.media.url;
            image.loading = 'lazy';
            image.decoding = 'async';
            image.alt = item.label;
            link.append(image);
            figure.append(link);
        }

        if (withTime) {
            const caption = document.createElement('figcaption');
            caption.className = 'font-monospace';
            caption.textContent = this.timeText(item.atMs, start);
            figure.append(caption);
        }

        return figure;
    }

    // ---------------------------------------------------------------- confirming

    keydown(event) {
        const action = reviewKey(event);

        if (action === null || this.closed || document.querySelector('.modal.show') !== null) {
            return;
        }

        event.preventDefault();

        switch (action) {
            case 'up':
                this.moveSelection(-1);
                break;
            case 'down':
                this.moveSelection(1);
                break;
            case 'left':
                this.stepFrame(-1);
                break;
            case 'right':
                this.stepFrame(1);
                break;
            case 'confirm':
                this.confirmFinish();
                break;
            case 'unfinished':
                this.confirmUnfinished();
                break;
            default:
                break;
        }
    }

    confirmFinish() {
        if (this.detail === null || this.tapId === null || !Number.isFinite(this.momentMs)) {
            return;
        }

        const body = momentConfirmation(this.momentMs, this.detail.taps ?? []);

        if (body !== null) {
            this.send(body);
        }
    }

    confirmUnfinished(event = null) {
        event?.preventDefault?.();

        if (this.detail === null || this.detail.entry.onlineEntryId === null) {
            return;
        }

        const body = piecesConfirmation(this.piecesTarget.value, this.detail.round?.piecesTotal ?? null);
        this.piecesErrorTarget.hidden = body !== null;

        if (body === null) {
            this.piecesTarget.focus();

            return;
        }

        this.send(body);
    }

    /**
     * One confirmation - with the official result this participant's evidence showed when it was opened (`seenResult`):
     * a result somebody entered since then comes back as a conflict, never overwritten.
     */
    async send(body) {
        if (this.busy || this.selectedId === null) {
            return;
        }

        const participantId = this.selectedId;
        this.busy = true;
        this.renderBusy();

        const answer = await officialResultsRequest(this.confirmUrl(participantId), {
            method: 'POST',
            body: { ...body, seenResult: this.seenResult },
            csrfToken: this.csrfTokenValue,
        });

        this.busy = false;
        this.renderBusy();

        if (answer.kind === 'client' && answer.status === 409 && answer.data?.entry) {
            this.changedMeanwhile(participantId, answer.data);

            return;
        }

        if (answer.kind !== 'ok' || !answer.data?.entry) {
            this.notify(this.refusalText(answer), 'danger');

            return;
        }

        const entry = answer.data.entry;
        this.replaceEntry(entry);

        const done = { name: entry.name, result: this.resultText(answer.data.result) ?? '' };
        this.notify(answer.data.status === 'unchanged' ? this.t('done_unchanged', done) : this.t('done_applied', done), 'success');

        this.renderList();

        if (this.detail?.entry?.participantId === entry.participantId) {
            this.detail.entry = entry;
            this.seenResult = entry.official ?? null;
            this.renderDetailHeader();
        }

        // On to the next one still to look at
        const rows = this.rows();
        const next = nextToReview(rows, rows.findIndex((candidate) => candidate.participantId === participantId));

        if (next >= 0 && rows[next].participantId !== participantId) {
            this.select(rows[next].participantId);
        }
    }

    /**
     * Somebody entered another official result since the reviewer opened this participant (the results desk, another
     * reviewer): nothing was written. The review shows that result and stays on the participant - the reviewer decides
     * again, now with what they see.
     */
    changedMeanwhile(participantId, data) {
        this.replaceEntry(data.entry);
        this.renderList();

        if (this.detail?.entry?.participantId === participantId) {
            this.detail.entry = data.entry;
            this.seenResult = data.current ?? null;
            this.renderDetailHeader();
        }

        this.notify(this.t('conflict', { result: this.resultText(data.current ?? null) ?? this.t('no_result') }), 'warning');
    }

    replaceEntry(entry) {
        const entries = this.list.entries ?? [];
        const index = entries.findIndex((candidate) => candidate.participantId === entry.participantId);

        if (index >= 0) {
            entries[index] = entry;
        }
    }

    refusalText(answer) {
        switch (answer.kind) {
            case 'client':
                return typeof answer.data?.message === 'string' ? answer.data.message : this.t('error_failed');
            case 'auth':
                return this.t('error_sign_in');
            case 'forbidden':
                // A stale stateless token means a page from another sign-in
                return answer.data?.error === 'invalid_csrf_token' ? this.t('error_sign_in') : this.t('error_forbidden');
            case 'offline':
                return this.t('error_offline');
            default:
                return this.t('error_failed');
        }
    }

    renderBusy() {
        const tap = (this.detail?.taps ?? []).find((candidate) => candidate.eventId === this.tapId) ?? null;
        this.confirmFinishButtonTarget.disabled = this.busy || tap === null || !Number.isFinite(this.momentMs);
        this.confirmUnfinishedButtonTarget.disabled = this.busy;
    }

    notify(text, kind) {
        const notice = this.noticeTarget;
        notice.textContent = text;
        notice.className = `alert py-2 alert-${kind}`;
        notice.hidden = false;
        window.clearTimeout(this.noticeTimer);

        if (kind === 'success') {
            this.noticeTimer = window.setTimeout(() => {
                notice.hidden = true;
            }, 5000);
        }
    }
}

function flagNodes(country) {
    if (typeof country !== 'string' || !/^[a-z]{2}$/.test(country)) {
        return [];
    }

    const flag = document.createElement('span');
    flag.className = `fi fi-${country} shadow-custom me-1`;
    flag.setAttribute('aria-hidden', 'true');

    return [flag];
}

function closeBitmaps(burst) {
    for (const bitmap of burst?.bitmaps?.values() ?? []) {
        bitmap?.close?.();
    }

    burst?.bitmaps?.clear();
}

/**
 * One JPEG frame of a burst, decoded: createImageBitmap where the browser has it, an <img> on an object URL else.
 */
async function decodeFrame(blob) {
    if (typeof createImageBitmap === 'function') {
        return createImageBitmap(blob);
    }

    const url = URL.createObjectURL(blob);

    try {
        const image = new Image();
        image.src = url;
        await image.decode();

        return image;
    } finally {
        URL.revokeObjectURL(url);
    }
}
