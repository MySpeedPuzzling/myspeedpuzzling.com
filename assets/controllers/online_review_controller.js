/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { formatClock } from '../online/round_state.js';
import {
    finishMoments,
    formatAdjustment,
    formatElapsed,
    frameSlices,
    momentConfirmation,
    nearestFrame,
    nextMoment,
    nextToReview,
    piecesConfirmation,
    piecesPlaced,
    reloadedLine,
    resultSeconds,
    reviewKey,
    stepIndex,
} from '../online/review_logic.js';
import { checkBadge, gapBoxes, reviewOrder } from '../online/review_check.js';
import { gapMark, GapMarks } from '../online/review_gaps.js';
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
// Shown with their tap / candidate, not in the timeline
const TAP_MEDIA = ['button', 'candidate', 'burst'];

/**
 * The review of an online round (templates/online_rounds/review.html.twig, docs/features/online-competitions/README.md
 * §5.9) - keyboard first, so one person gets through a round quickly:
 *
 * - the list (OnlineReview::list(), read again every LIST_REFRESH_MS and after another device's save on the round's
 *   private results topic): ↑/↓ choose a participant; their evidence (online_review_entry) loads at once;
 * - the finish moments - every Finished tap and (Phase 2) every finish candidate, raised hands the phone detected, in
 *   time (review_logic.js finishMoments()): one opens its photo (`button` / `candidate`) and its burst - the one object
 *   is fetched once through its short-lived URL, its frames sliced by the manifest (review_logic.js frameSlices()) and
 *   decoded on demand (createImageBitmap); ←/→ or the slider step through them, every frame's time on the round's
 *   clock - the moment becomes the frame's. The review opens on the suggested one (in a round whose detection is `on`
 *   the first candidate); G - its photo shows a gap - moves on to the next moment;
 * - C confirms the finish (a candidate's own moment = source `detection`, a tap's = `button`, a frame = `reviewer`), U
 *   confirms unfinished with the pieces placed (online_review_confirm); the answer replaces the row, the next
 *   participant to look at follows. A refusal is shown with its reason - never silently.
 * - Phase 3, only while the completeness check is on (`round.completenessCheck` - off, none of it): every finish photo
 *   carries the automatic check's badge and its suspected gaps drawn over it (review_check.js; the switch hides them),
 *   and the list puts "needs a look" first (reviewOrder()). Advisory: nothing here reads it to suggest or confirm.
 * - Stream RG - G is stored: the moment G leaves is marked "Gap" in the moments list (online_review_gap, kept by
 *   review_gaps.js GapMarks: shown at once, sent after, sent again when the page is back online; the detail's `gaps`
 *   after a reload); "Not a gap" - or confirming that very moment - takes the mark back. G on the last moment marks it
 *   and says there is nothing after it. A raise marked as a gap is never suggested (the saved mark's answer brings
 *   the row as it is now) nor ever a finish's raise in the accuracy report.
 */
export default class extends Controller {
    static targets = [
        'initialState', 'progress', 'notice', 'list', 'listEmpty', 'detail', 'detailPlaceholder', 'detailLoading', 'detailError',
        'detailBody', 'detailName', 'detailOfficial', 'detailStatus', 'detailFlags', 'notConnected', 'finishPanel', 'taps',
        'evidence', 'canvas', 'burstStatus', 'slider', 'frameButton', 'frameLabel', 'tapPhotoLink', 'tapPhoto',
        'tapPhotoPending', 'momentBox', 'momentTime', 'momentLine', 'confirmFinishButton', 'unfinishedPanel', 'reported',
        'pieces', 'piecesOf', 'piecesError', 'confirmUnfinishedButton', 'endPhotoBox', 'endPhotoLink', 'endPhoto', 'timeline',
        'burstCaption', 'photoCaption', 'toMomentButton', 'gapButton', 'notGapButton',
        // The completeness check (Phase 3) - rendered only while it is on
        'tapPhotoCheckLine', 'tapPhotoCheck', 'tapPhotoBoxes', 'showGaps', 'showGapsSwitch', 'endPhotoCheck', 'endPhotoBoxes',
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
        // The reviewer's gap marks (stream RG) - shown at once, saved after
        this.gapMarks = new GapMarks({
            send: ({ participantId, eventId, gap }) => officialResultsRequest(this.gapUrl(participantId), {
                method: 'POST',
                body: { eventId, gap },
                csrfToken: this.csrfTokenValue,
            }).then((answer) => {
                // A raise marked as a gap is never suggested - the row may suggest the next moment now
                if (answer.kind === 'ok' && answer.data?.entry) {
                    this.gapEntryChanged(answer.data.entry);
                }

                return answer;
            }),
            changed: () => this.gapMarksChanged(),
            report: (report) => this.gapReport(report),
        });
        this.onOnline = () => this.gapMarks.retry();
        window.addEventListener('online', this.onOnline);

        this.onKeydown = (event) => this.keydown(event);
        document.addEventListener('keydown', this.onKeydown);
        this.onVisibility = () => {
            if (document.visibilityState === 'visible') {
                this.refreshList();
                this.gapMarks.retry();
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
                this.gapMarks.retry();
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
        window.removeEventListener('online', this.onOnline);
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
        // "Needs a look" first while the completeness check is on - else as listed (by name)
        const entries = reviewOrder(Array.isArray(this.list.entries) ? this.list.entries : [], this.checkOn());

        return this.filter === 'all' ? entries : entries.filter((entry) => entry.status === this.filter);
    }

    /**
     * The completeness check is on for this round's review (Phase 3) - the server says so only then
     */
    checkOn() {
        return this.list?.round?.completenessCheck === true;
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

        // Why it is where it is in the list: the check of the photo the review opens on - until it is confirmed
        if (this.checkOn() && entry.status !== 'confirmed') {
            const badge = this.checkBadgeElement(entry.check ?? null);

            if (badge !== null) {
                name.append(document.createTextNode(' '), badge);
            }
        }

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

                // New taps or candidates, or a decision from elsewhere - the evidence shown is read again
                if (this.selectedId !== null && after !== null && (before === null || before.taps !== after.taps || before.candidates !== after.candidates || before.status !== after.status || JSON.stringify(before.official) !== JSON.stringify(after.official))) {
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

    gapUrl(participantId) {
        return this.urlsValue.gap.replace(this.placeholderValue, participantId);
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
        // The moments marked as showing a gap, as the server has them (a mark not saved yet still shows)
        this.gapMarks.load(participantId, answer.data.gaps ?? []);

        // What the reviewer sees now - the `from` of their confirmation. A refresh in the background (keep) leaves it:
        // a result entered meanwhile must come back as a conflict when they confirm, never pass as the one they saw
        if (!keep) {
            this.seenResult = answer.data.entry.official ?? null;
        }

        this.detailBodyTarget.hidden = false;
        this.detailErrorTarget.hidden = true;
        this.renderDetailHeader();

        const moments = this.moments();
        const entry = this.detail.entry;
        let tapId = null;
        let momentMs = null;

        if (keep && moments.some((moment) => moment.id === keep.tapId)) {
            tapId = keep.tapId;
            momentMs = keep.momentMs;
        } else if (entry.confirmed && Number.isFinite(entry.confirmed.momentMs) && moments.length > 0) {
            // What was confirmed - its tap or candidate (a candidate's own moment wins a tie), its moment
            momentMs = entry.confirmed.momentMs;
            tapId = moments.reduce((best, moment) => (Math.abs(moment.momentMs - momentMs) < Math.abs(best.momentMs - momentMs) ? moment : best)).id;
        } else if (entry.suggestion?.candidateEventId && moments.some((moment) => moment.id === entry.suggestion.candidateEventId)) {
            // Detection on: the first candidate - its photo decides (G moves on)
            tapId = entry.suggestion.candidateEventId;
        } else if (entry.suggestion?.tapEventId) {
            tapId = entry.suggestion.tapEventId;
        } else if (moments.length > 0) {
            tapId = moments[moments.length - 1].id;
        }

        this.renderUnfinished();
        this.renderTimeline();
        this.chooseTap(tapId, { momentMs });
    }

    /**
     * The participant's finish moments in time - taps and finish candidates (review_logic.js finishMoments())
     */
    moments() {
        return finishMoments(this.detail?.taps ?? [], this.detail?.candidates ?? []);
    }

    /**
     * The moment the review stands on (a tap or a candidate) - null without one
     */
    currentMoment() {
        return this.moments().find((moment) => moment.id === this.tapId) ?? null;
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

        // How far the detected moment was from the confirmed one (Phase 2 - the accuracy report's number)
        if (Number.isFinite(entry.confirmed?.detectionErrorMs)) {
            line += ` · ${this.t('detection_error', { adjustment: formatAdjustment(entry.confirmed.detectionErrorMs) })}`;
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

    /**
     * Stands on a moment - a tap or a finish candidate (its id): its photo, its burst, the moment to confirm
     */
    chooseTap(tapId, { momentMs = null } = {}) {
        const moment = this.moments().find((item) => item.id === tapId) ?? null;
        this.tapId = moment?.id ?? null;
        this.momentMs = moment === null ? null : (Number.isFinite(momentMs) ? momentMs : moment.momentMs);
        this.burst = null;
        this.frameIndex = -1;
        this.renderTaps();
        this.evidenceTarget.hidden = moment === null;
        this.momentBoxTarget.hidden = moment === null;

        if (moment === null) {
            this.renderPhotoCheck(null, 'tap');
            this.renderMoment();

            return;
        }

        const detected = moment.kind === 'candidate';
        this.burstCaptionTarget.textContent = this.t(detected ? 'burst_title_candidate' : 'burst_title_tap');
        this.photoCaptionTarget.textContent = this.t(detected ? 'photo_title_candidate' : 'photo_title_tap');
        this.toMomentButtonTarget.textContent = this.t(detected ? 'to_candidate' : 'to_tap');

        const media = this.detail.media ?? [];
        const photo = this.tapMedia(media, detected ? 'candidate' : 'button', moment.id);
        this.showPhoto(this.tapPhotoLinkTarget, this.tapPhotoTarget, photo);
        this.renderPhotoCheck(photo, 'tap');
        // Registered but not uploaded yet (the phone was offline, a store outage) - said, never hidden; one that never
        // arrived (the phone gave its URL up, nothing came later) says so
        this.tapPhotoPendingTarget.hidden = !(photo !== null && photo.url === null);
        this.tapPhotoPendingTarget.textContent = this.t(photo?.abandoned ? 'never_arrived' : 'pending');

        const burst = this.tapMedia(media, 'burst', moment.id);
        this.renderMoment();
        this.openBurst(burst, moment);
    }

    /**
     * G - the photo shows a gap: the moment is marked (stored - stream RG) and the review goes on to the next moment (a
     * later raise of the hands, a tap). Said when there is none - the mark stays.
     */
    gapNext() {
        if (this.detail === null || this.tapId === null) {
            return;
        }

        this.gapMarks.set(this.detail.entry.participantId, this.tapId, true);
        const next = nextMoment(this.moments(), this.tapId);

        if (next === null) {
            this.notify(this.t('gap_last'), 'info');

            return;
        }

        this.chooseTap(next.id);
    }

    /**
     * "Not a gap" - the mark on the moment the review stands on is taken back
     */
    notGap() {
        if (this.detail === null || this.tapId === null) {
            return;
        }

        this.gapMarks.set(this.detail.entry.participantId, this.tapId, false);
    }

    /**
     * A mark shown changed (set, saved, refused) - the moments list and the moment's buttons say so
     */
    gapMarksChanged() {
        if (this.detail === null || this.closed) {
            return;
        }

        this.renderTaps();
        this.renderBusy();
    }

    /**
     * A mark saved: the participant's row as it is now (its suggestion skips a raise marked as a gap) - the list and
     * the header show it; the result the reviewer saw (`seenResult`) stays, a mark changes no official result
     */
    gapEntryChanged(entry) {
        if (this.closed) {
            return;
        }

        this.replaceEntry(entry);
        this.renderList();

        if (this.detail?.entry?.participantId === entry.participantId) {
            this.detail.entry = entry;
            this.renderDetailHeader();
        }
    }

    /**
     * What happened to the marks on their way: not saved (offline - kept and sent again; signed out), saved after all,
     * refused (dropped, with the server's reason)
     */
    gapReport(report) {
        switch (report.kind) {
            case 'offline':
                this.notify(this.t('gap_offline'), 'warning');
                break;
            case 'auth':
                this.notify(this.t('error_sign_in'), 'danger');
                break;
            case 'saved':
                this.notify(this.t('gap_saved'), 'success');
                break;
            default:
                this.notify(this.refusalText(report.answer ?? {}), 'danger');
                break;
        }
    }

    /**
     * The tap's photo or burst: the latest one that arrived - a retry after a store failure is a new row, the one given
     * up never arrives - else the latest one
     */
    tapMedia(media, kind, eventId) {
        const rows = media.filter((item) => item.kind === kind && item.relatedEventId === eventId);

        return rows.filter((item) => item.url !== null).pop() ?? rows.pop() ?? null;
    }

    selectTap(event) {
        this.chooseTap(event.currentTarget.dataset.tapId);
    }

    renderTaps() {
        const moments = this.moments();
        const start = this.startedAtMs();
        const detection = this.detail?.round?.detection ?? 'off';

        if (moments.length === 0) {
            const none = document.createElement('p');
            none.className = 'small text-body-secondary mb-0';
            // `shadow` shows no raised hands (the server sends none): taps only, as with detection off
            none.textContent = this.t(detection === 'on' ? 'no_moments' : 'no_taps');
            this.tapsTarget.replaceChildren(none);

            return;
        }

        this.tapsTarget.replaceChildren(...moments.map((moment) => {
            const tap = moment.item;
            const detected = moment.kind === 'candidate';
            const selected = moment.id === this.tapId;
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `btn btn-sm ${selected ? (detected ? 'btn-success' : 'btn-primary') : (detected ? 'btn-outline-success' : 'btn-outline-primary')}`;
            button.dataset.tapId = moment.id;
            button.dataset.momentKind = moment.kind;
            button.setAttribute('aria-pressed', selected ? 'true' : 'false');
            button.addEventListener('click', (event) => this.selectTap(event));

            if (detected) {
                const icon = document.createElement('i');
                icon.className = 'bi bi-person-raised-hand me-1';
                icon.setAttribute('aria-hidden', 'true');
                button.append(icon);
            }

            button.append(document.createTextNode(this.t(detected ? 'candidate' : 'tap', {
                number: moment.number,
                time: Number.isFinite(start) ? formatElapsed(tap.momentMs - start) : new Date(tap.momentMs).toLocaleTimeString(),
            })));

            const notes = [];

            if (detected) {
                const details = [];

                if (Number.isFinite(tap.score)) {
                    details.push(this.t('candidate_score', { score: tap.score.toFixed(2) }));
                }

                if (tap.view === 'angled' || tap.view === 'topDown') {
                    details.push(this.t(tap.view === 'angled' ? 'view_angled' : 'view_top_down'));
                }

                notes.push(...details);
            }

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

            // The reviewer's G on this moment (stream RG) - "not saved yet" while the server does not have it
            const mark = gapMark(this.gapMarks, this.detail.entry.participantId, moment.id);

            if (mark !== null) {
                const badge = document.createElement('span');
                badge.className = `badge ms-1 ${mark.unsaved ? 'text-bg-light border border-warning' : 'text-bg-warning'}`;
                badge.dataset.gapMark = mark.unsaved ? 'unsaved' : 'saved';
                badge.textContent = this.t(mark.text);
                badge.title = this.t(mark.title);
                button.append(badge);
            }

            return button;
        }));
    }

    // ---------------------------------------------------------------- the burst

    /**
     * A moment's burst (`moment` from finishMoments() - a tap's or a candidate's)
     */
    async openBurst(media, moment) {
        this.setBurstStatus('');
        this.sliderTarget.max = '0';
        this.sliderTarget.value = '0';
        this.frameLabelTarget.textContent = '';
        this.clearCanvas();
        // The candidate's own words ("around these raised hands") - a tap's are the Phase 1 texts
        const suffix = moment.kind === 'candidate' ? '_candidate' : '';

        if (media === null) {
            this.setBurstStatus(this.t(`burst_none${suffix}`));

            return;
        }

        if (media.url === null) {
            this.setBurstStatus(this.t(media.abandoned ? `burst_never_arrived${suffix}` : `burst_pending${suffix}`));

            return;
        }

        this.setBurstStatus(this.t('burst_loading'));
        const tapId = moment.id;
        const tap = moment;
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
            this.setBurstStatus(this.t(`burst_none${suffix}`));

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

    /**
     * Back to the tap's - or the candidate's - own moment
     */
    backToTap() {
        const tap = this.currentMoment();

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
        const tap = this.currentMoment();
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
            const key = tap.kind === 'candidate' ? 'moment_moved_candidate' : 'moment_moved';
            line += `${line === '' ? '' : ' · '}${this.t(key, { adjustment: formatAdjustment(this.momentMs - tap.momentMs) })}`;
        } else if (tap.kind === 'candidate') {
            line += `${line === '' ? '' : ' · '}${this.t('moment_detected')}`;
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
            : (measure === 'placed' ? this.tc('reported_placed', reportedPieces) : this.tc('reported_left', reportedPieces));

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
        this.renderPhotoCheck(end, 'end');
    }

    // ---------------------------------------------------------------- the completeness check (Phase 3)

    /**
     * A photo's check badge - "Looks complete" / "Gap?" / "Not sure" / "Not checked", the reason in its title; null
     * without a check
     */
    checkBadgeElement(check) {
        const badge = checkBadge(check);

        if (badge === null) {
            return null;
        }

        const element = document.createElement('span');
        element.className = `badge online-check-badge online-check-badge--${badge.tone}`;
        element.dataset.check = badge.state;
        element.textContent = this.t(badge.text);
        const reason = typeof check?.reason === 'string' && check.reason !== '' ? this.t('check_reason', { reason: check.reason }) : null;
        element.title = reason === null ? this.t(badge.title) : `${this.t(badge.title)}\n${reason}`;

        return element;
    }

    /**
     * The check of the photo shown at the moment (`tap` - a tap's or a candidate's photo) or at time-up (`end`): its
     * badge and its suspected gaps over it, scaled with the photo (percentages of the size the check measured, else of
     * the image's own). Nothing while the check is off - the page has no place for it then.
     */
    renderPhotoCheck(media, which) {
        const slot = which === 'tap' ? (this.hasTapPhotoCheckTarget ? this.tapPhotoCheckTarget : null) : (this.hasEndPhotoCheckTarget ? this.endPhotoCheckTarget : null);
        const layer = which === 'tap' ? (this.hasTapPhotoBoxesTarget ? this.tapPhotoBoxesTarget : null) : (this.hasEndPhotoBoxesTarget ? this.endPhotoBoxesTarget : null);

        if (slot === null || layer === null) {
            return;
        }

        const check = media?.check ?? null;
        const badge = this.checkBadgeElement(check);
        slot.replaceChildren(...(badge === null ? [] : [badge]));

        if (which === 'tap' && this.hasTapPhotoCheckLineTarget) {
            this.tapPhotoCheckLineTarget.hidden = badge === null;
        }

        const image = which === 'tap' ? this.tapPhotoTarget : this.endPhotoTarget;
        const draw = () => {
            const boxes = gapBoxes(check, image.naturalWidth || null, image.naturalHeight || null);

            // The switch only next to a photo with suspected gaps
            if (which === 'tap' && this.hasShowGapsSwitchTarget) {
                this.showGapsSwitchTarget.hidden = boxes.length === 0;
            }

            layer.replaceChildren(...boxes.map((box) => {
                // Placed by its middle: the stylesheet keeps a small gap's box large enough to be seen
                const element = document.createElement('span');
                element.className = 'online-check-box';
                element.style.left = `${box.centerX}%`;
                element.style.top = `${box.centerY}%`;
                element.style.width = `${box.width}%`;
                element.style.height = `${box.height}%`;
                element.title = this.t('check_gap_box');

                return element;
            }));
        };

        draw();

        // Without the size from the check the boxes wait for the image's own
        if (check?.state === 'gaps' && !(check.imageWidth > 0) && !image.complete) {
            image.addEventListener('load', draw, { once: true });
        }
    }

    /**
     * The switch next to the photo: the suspected gaps shown or not (every photo of the page)
     */
    toggleGaps() {
        this.element.classList.toggle('online-review--gaps-hidden', this.hasShowGapsTarget && !this.showGapsTarget.checked);
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
        // The pieces reported when time was up, said as on the result panel ("… 12 pieces left") - never the bare code
        if (event.type === 'timeup.pieces' && Number.isInteger(event.data?.pieces)) {
            return (event.data.measure ?? this.detail?.round?.unfinishedMeasure) === 'placed'
                ? this.tc('reported_placed', event.data.pieces)
                : this.tc('reported_left', event.data.pieces);
        }

        // The phone's detector started watching (again) - or stopped: Finished button only (stream P2F)
        if (event.type === 'detector.changed' && event.data?.running === true) {
            return this.t('event_detector_running');
        }

        // A page (re)opened during the round, with how long it was gone when the phone knew (stream RP)
        if (event.type === 'page.reloaded') {
            const line = reloadedLine(event.data);

            return this.t(line.key, line.params);
        }

        const key = `event_${String(event.type).replaceAll('.', '_')}`;

        return this.textsValue[key] === undefined ? event.type : this.t(key);
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
            pending.textContent = this.t(item.media.abandoned ? 'never_arrived' : 'pending');
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
            case 'gap':
                this.gapNext();
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

        // A candidate's own moment = source detection, a tap's = button, a frame the reviewer moved to = reviewer
        const body = momentConfirmation(this.momentMs, this.detail.taps ?? [], this.detail.candidates ?? []);

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
        // A finish confirmed at the very moment the review stands on (its own moment - not a frame moved to) is no gap
        const momentId = body.momentMs !== null && body.source !== 'reviewer' ? this.tapId : null;
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

        if (momentId !== null && this.gapMarks.isGap(participantId, momentId)) {
            this.gapMarks.set(participantId, momentId, false);
        }

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
            // A confirmation's answer carries no completeness check - the row keeps the one it had until the next read
            entries[index] = entry.check === undefined && entries[index].check !== undefined ? { ...entry, check: entries[index].check } : entry;
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
        const tap = this.currentMoment();
        this.confirmFinishButtonTarget.disabled = this.busy || tap === null || !Number.isFinite(this.momentMs);
        this.confirmUnfinishedButtonTarget.disabled = this.busy;
        // Only with the round's detection on (review.html.twig) - on the last moment too: G marks it, nothing follows
        if (this.hasGapButtonTarget) {
            this.gapButtonTarget.disabled = this.busy || tap === null;
        }

        // "Not a gap" - only on a moment marked as showing one (stream RG)
        if (this.hasNotGapButtonTarget) {
            this.notGapButtonTarget.hidden = tap === null || this.detail === null || !this.gapMarks.isGap(this.detail.entry.participantId, tap.id);
        }
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
