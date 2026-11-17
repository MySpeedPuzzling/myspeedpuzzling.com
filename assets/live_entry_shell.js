/**
 * The offline copy of the live entry page at work (competition toolkit C5, docs/features/competitions-management/
 * toolkit/C-judge-page.md §1.6): which of the device's round snapshots (live_entry_snapshot.js) the page shows for the
 * URL it was opened at (live_entry_route.js), and which neutral screen it shows when it cannot show one. Pure - run by
 * tests/live-entry-shell-harness.mjs.
 */

import { entryOfParticipant, preferredRound } from './official_results_live.js';
import { entryOfTag } from './live_entry_tag.js';

const RECENTLY_STARTED_MS = 12 * 3600 * 1000;

function momentOf(iso) {
    if (typeof iso !== 'string' || iso === '') {
        return null;
    }

    const ms = Date.parse(iso);

    return Number.isNaN(ms) ? null : ms;
}

function compare(a, b) {
    for (let i = 0; i < a.length; i += 1) {
        if (a[i] < b[i]) {
            return -1;
        }

        if (a[i] > b[i]) {
            return 1;
        }
    }

    return 0;
}

function startedAt(round, nowMs) {
    const stopwatchStart = momentOf(round.stopwatch?.startedAt);

    if (stopwatchStart !== null && stopwatchStart <= nowMs) {
        return stopwatchStart;
    }

    const startsAt = momentOf(round.startsAt);

    return startsAt !== null && startsAt <= nowMs ? startsAt : null;
}

function latestStarted(rounds, nowMs) {
    const sorted = [...rounds].sort((a, b) => compare(
        [startedAt(b, nowMs) ?? momentOf(b.startsAt) ?? 0, momentOf(b.startsAt) ?? 0, String(a.name ?? '')],
        [startedAt(a, nowMs) ?? momentOf(a.startsAt) ?? 0, momentOf(a.startsAt) ?? 0, String(b.name ?? '')],
    ));

    return sorted[0];
}

/**
 * The round a referee most likely enters results for right now - LiveResultsCurrentRound::pick() in PHP, the same
 * rules (pinned by the shared cases tests/fixtures/live-current-round-cases.json, run by both): the running stopwatch
 * (the latest started of several), else the round started last within 12 hours, else the next one, else the last one.
 *
 * @param {Array<object>} rounds  rounds as the state JSON has them (`id`, `name`, `startsAt`, `stopwatch`)
 * @returns {object|null}
 */
export function currentRound(rounds, nowMs) {
    const list = Array.isArray(rounds) ? rounds.filter((round) => round && typeof round.id === 'string') : [];

    if (list.length === 0) {
        return null;
    }

    const running = list.filter((round) => {
        const status = round.stopwatch?.status ?? null;
        const start = momentOf(round.stopwatch?.startedAt);

        // RoundStopwatchStatus::at(): a scheduled or running start counts as running once its moment (whole seconds) has come
        return (status === 'scheduled' || status === 'running') && start !== null && Math.floor(start / 1000) <= Math.floor(nowMs / 1000);
    });

    if (running.length > 0) {
        return latestStarted(running, nowMs);
    }

    const recent = list.filter((round) => {
        const start = startedAt(round, nowMs);

        return start !== null && start >= nowMs - RECENTLY_STARTED_MS;
    });

    if (recent.length > 0) {
        return latestStarted(recent, nowMs);
    }

    const upcoming = list.filter((round) => (momentOf(round.startsAt) ?? -Infinity) > nowMs)
        .sort((a, b) => compare([momentOf(a.startsAt), String(a.name ?? '')], [momentOf(b.startsAt), String(b.name ?? '')]));

    if (upcoming.length > 0) {
        return upcoming[0];
    }

    return [...list].sort((a, b) => compare([momentOf(b.startsAt) ?? 0, String(b.name ?? '')], [momentOf(a.startsAt) ?? 0, String(a.name ?? '')]))[0];
}

function roundOf(snapshot) {
    const round = snapshot.state?.round;

    return round && typeof round === 'object' ? { ...round, id: snapshot.roundId } : { id: snapshot.roundId };
}

function pickSnapshot(snapshots, nowMs) {
    const round = currentRound(snapshots.map(roundOf), nowMs);

    return round === null ? null : snapshots.find((snapshot) => snapshot.roundId === round.id) ?? null;
}

/**
 * What the offline copy shows:
 * - `{screen: 'signedOut'}` - the device names no player (signed out, never a referee here): nothing of anybody;
 * - `{screen: 'round', snapshot, landing}` - the round from its snapshot; `landing` (live_entry_page.js landingOf()
 *   shape, or `{kind: 'tag', tag}`) opens the name tag's entrant or the scanned tag's entry, null for nothing;
 * - `{screen: 'noCopy', roundId, others}` - no snapshot for what was asked for: `roundId` when the URL named a round
 *   (the page keeps asking for it), `others` = the player's snapshots to choose from (links), the URL's event first.
 *
 * @param {object} options
 * @param {object|null} options.route         parseLiveEntryRoute()
 * @param {string|null} options.player        the player the device cookie names (live_entry_device.js devicePlayerOf())
 * @param {Array<object>} options.snapshots   the player's snapshots (SnapshotStore.list()), newest first
 * @param {number} options.nowMs
 * @param {function(string): (object|null)} [options.remembered]  the round a referee picked by hand in an event
 *        (`{roundId, at}` of the page's localStorage) - as preferredRound() takes it
 */
export function shellPlan({ route, player, snapshots, nowMs, remembered = () => null }) {
    if (typeof player !== 'string' || player === '') {
        return { screen: 'signedOut' };
    }

    const own = (Array.isArray(snapshots) ? snapshots : []).filter((snapshot) => snapshot && typeof snapshot.roundId === 'string');
    const competitionId = typeof route?.competitionId === 'string' ? route.competitionId : null;
    const noCopy = () => ({
        screen: 'noCopy',
        roundId: route?.kind === 'round' ? route.roundId : null,
        others: competitionId === null ? own : [
            ...own.filter((snapshot) => String(snapshot.competitionId ?? '').toLowerCase() === competitionId),
            ...own.filter((snapshot) => String(snapshot.competitionId ?? '').toLowerCase() !== competitionId),
        ],
    });

    if (route === null || route === undefined) {
        return noCopy();
    }

    if (route.kind === 'round') {
        const snapshot = own.find((candidate) => candidate.roundId.toLowerCase() === route.roundId);

        return snapshot ? { screen: 'round', snapshot, landing: null } : noCopy();
    }

    const ofEvent = own.filter((snapshot) => String(snapshot.competitionId ?? '').toLowerCase() === competitionId);

    if (ofEvent.length === 0) {
        return noCopy();
    }

    if (route.kind === 'participant') {
        // As LiveResultsScanController: the entrant's current round, else the event's current round (it says they
        // are not in it)
        const theirs = ofEvent.filter((snapshot) => entryOfParticipant(Array.isArray(snapshot.state?.entries) ? snapshot.state.entries : [], route.id) !== null);
        const snapshot = pickSnapshot(theirs, nowMs) ?? pickSnapshot(ofEvent, nowMs);

        return { screen: 'round', snapshot, landing: { kind: 'entrant', participantId: route.id } };
    }

    const picked = pickSnapshot(ofEvent, nowMs);

    if (route.kind === 'event') {
        // LiveResultsEventController + the page's `auto` pick: a round picked by hand within 12 hours wins
        const preferred = preferredRound(picked.roundId, remembered(competitionId), ofEvent.map(roundOf), nowMs);

        return { screen: 'round', snapshot: ofEvent.find((snapshot) => snapshot.roundId === preferred) ?? picked, landing: null };
    }

    // A line-up, table or entry tag (toolkit C8) - as CompetitionTagResolver: the tag's own round, else the event's
    // current round (which then says why the tag is not in it)
    const tag = { competitionId, kind: route.kind, id: route.id };

    return { screen: 'round', snapshot: snapshotOfTag(ofEvent, tag, nowMs) ?? picked, landing: { kind: 'tag', tag } };
}

/**
 * The snapshot among one event's that the tag means (CompetitionTagResolver): a round-entry tag's own round, else the
 * current round among those the tag plays in. Null when it is in none of them.
 */
export function snapshotOfTag(snapshots, tag, nowMs) {
    const theirs = snapshots.filter((snapshot) => entryOfTag(snapshot.state?.entries, tag) !== null);

    return tag.kind === 'entry' ? theirs[0] ?? null : pickSnapshot(theirs, nowMs);
}

/**
 * The sign-in page's URL coming back to `returnPath` (a path of this site - the server checks it, ReturnUrl).
 */
export function loginUrlWithReturn(loginUrl, returnPath) {
    if (typeof returnPath !== 'string' || !returnPath.startsWith('/') || returnPath.startsWith('//')) {
        return loginUrl;
    }

    return `${loginUrl}${String(loginUrl).includes('?') ? '&' : '?'}return=${encodeURIComponent(returnPath)}`;
}

/**
 * The URL the page takes once it is live again (history.replaceState): the round's own live entry URL - an event or a
 * tag URL would pick again on a reload. Null when the page is at that URL already.
 */
export function liveUrlOnceLive(currentHref, liveUrl) {
    try {
        const current = new URL(currentHref);
        const target = new URL(liveUrl, current);

        return current.pathname === target.pathname ? null : target.pathname;
    } catch (e) {
        return null;
    }
}
