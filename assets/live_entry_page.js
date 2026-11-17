/**
 * The live entry page filled from the round state (competition toolkit C1, docs/features/competitions-management/
 * judge-offline.md "Data-free page + state parameters (C1)"): the template renders no data of the round, the event or the person reading it, so
 * everything that differs per round or per reader is decided here from `official_results_round_state?viewer=1`.
 * Pure functions - run by tests/live-results-harness.mjs.
 */

import { entryOfParticipant } from './official_results_live.js';
import { entryOfTag } from './live_entry_tag.js';
import { parseLiveEntryRoute } from './live_entry_route.js';

export const UUID_PLACEHOLDER = '00000000-0000-0000-0000-000000000000';
export const COMPETITION_PLACEHOLDER = '11111111-1111-1111-1111-111111111111';

/**
 * The state endpoint's URL of a round: the page's template (round id placeholder) filled in, `viewer=1` (who reads it
 * - R §1.8: only the live entry asks for it) and `entries=0` (the clock and the token only).
 */
export function stateUrl(template, roundId, { viewer = true, entries = true } = {}) {
    const url = template.replace(UUID_PLACEHOLDER, roundId);
    const params = [];

    if (viewer) {
        params.push('viewer=1');
    }

    if (!entries) {
        params.push('entries=0');
    }

    return params.length > 0 ? `${url}${url.includes('?') ? '&' : '?'}${params.join('&')}` : url;
}

/**
 * What the page shows for a state: the variants the template carries hidden.
 *
 * @returns {{usesTables: boolean, solo: boolean, members: number, organiser: boolean, canSetTables: boolean, playerId: string|null, competitionId: string|null, roundName: string}}
 */
export function pageSettings(state) {
    const round = state?.round ?? null;
    // Nothing known yet: no table bits (they appear with the round)
    const usesTables = round !== null && state?.competition?.isOnline !== true && round.tableNumbersOff !== true;
    const category = round?.category ?? 'solo';
    const organiser = state?.viewer?.organiser === true;
    const playerId = typeof state?.viewer?.playerId === 'string' && state.viewer.playerId !== '' ? state.viewer.playerId : null;

    return {
        usesTables,
        solo: category === 'solo',
        // A pair is two, a team starts with three member fields ("Add another member" for more)
        members: category === 'solo' ? 0 : (category === 'duo' ? 2 : 3),
        organiser,
        // Table numbers only where this page can set them: a referee's are refused by the server (results only)
        canSetTables: organiser && usesTables,
        playerId,
        competitionId: typeof state?.competition?.id === 'string' ? state.competition.id : null,
        roundName: typeof round?.name === 'string' ? round.name : '',
    };
}

/**
 * The round picker's options: every round of the event, the page's round selected, a running stopwatch named.
 *
 * @returns {Array<{value: string, label: string, selected: boolean}>}
 */
export function roundOptions(rounds, currentRoundId, runningLabel) {
    return (Array.isArray(rounds) ? rounds : []).map((round) => ({
        value: round.id,
        label: round.stopwatch?.status === 'running' ? `${round.name} · ${runningLabel}` : String(round.name ?? ''),
        selected: round.id === currentRoundId,
    }));
}

/**
 * A link of the template (placeholders for the round and the event) for this page.
 */
export function fillLink(template, roundId, competitionId) {
    return template.split(UUID_PLACEHOLDER).join(roundId).split(COMPETITION_PLACEHOLDER).join(competitionId ?? COMPETITION_PLACEHOLDER);
}

/**
 * The landing parameters the page was opened with (checked by the server): `?entrant=<participantId>`,
 * `?table=<n>`, `?entry=<ref>`. Null when there is none. One at a time: entrant, then entry, then table.
 */
export function landingOf({ entrant = '', table = '', entry = '' } = {}) {
    if (typeof entrant === 'string' && entrant !== '') {
        return { kind: 'entrant', participantId: entrant.toLowerCase() };
    }

    if (typeof entry === 'string' && /^(participant_round|team):[0-9a-f-]{36}$/i.test(entry)) {
        return { kind: 'entry', ref: entry.toLowerCase() };
    }

    const number = typeof table === 'number' ? table : (/^[1-9]\d{0,3}$/.test(String(table ?? '')) ? parseInt(String(table), 10) : null);

    if (number !== null && number >= 1 && number <= 9999) {
        return { kind: 'table', number };
    }

    return null;
}

/**
 * Where a landing goes once the round's entries are known:
 * - `{open: ref}` - the entry to open;
 * - `{notice: key, parameters}` - a message key of the page with its placeholders;
 * - `{needPeople: true}` - an entrant who is no entry of this round: the event's people tell the name (ask again with
 *   them);
 * - null - nothing to do.
 *
 * @param {object|null} landing                 landingOf(), or `{kind: 'tag', tag}` (a scanned tag, toolkit C8)
 * @param {Array<object>} entries               the round's entries (the page's allEntries())
 * @param {Array<object>|null} people           the event's people who are no entry of the round, null = not loaded
 * @param {string} roundName
 */
export function resolveLanding(landing, entries, people, roundName) {
    if (landing === null || landing === undefined) {
        return null;
    }

    const list = Array.isArray(entries) ? entries : [];

    if (landing.kind === 'entrant') {
        const entry = entryOfParticipant(list, landing.participantId);

        if (entry) {
            return { open: entry.ref };
        }

        if (people === null) {
            return { needPeople: true };
        }

        const person = people.find((candidate) => String(candidate.participantId).toLowerCase() === landing.participantId);

        // Somebody of the event who is not going (waitlist, cancelled) is not among the people - the name tag's text
        return person
            ? { notice: 'entrantNotInRound', parameters: { '%name%': person.name, '%round%': roundName } }
            : { notice: 'scanNotInRound', parameters: { '%round%': roundName } };
    }

    if (landing.kind === 'entry') {
        const entry = list.find((candidate) => String(candidate.ref).toLowerCase() === landing.ref);

        return entry ? { open: entry.ref } : { notice: 'entryNotInRound', parameters: { '%round%': roundName } };
    }

    // A scanned line-up, table or entry tag (live_entry_tag.js; toolkit C8) - the scanner's and the offline copy's
    if (landing.kind === 'tag') {
        const { tag } = landing;
        const entry = entryOfTag(list, tag);

        if (entry) {
            return { open: entry.ref };
        }

        return tag.kind === 'table'
            ? { notice: 'tableEmpty', parameters: { '%number%': tag.id, '%round%': roundName } }
            : { notice: tag.kind === 'lineup' ? 'lineupNotInRound' : 'entryNotInRound', parameters: { '%round%': roundName } };
    }

    if (landing.kind === 'table') {
        const entry = list.find((candidate) => candidate.tableNumber === landing.number);

        return entry
            ? { open: entry.ref }
            : { notice: 'tableEmpty', parameters: { '%number%': landing.number, '%round%': roundName } };
    }

    return null;
}

/**
 * The page's own URL without the landing parameters - each does its job once, a reload must not land again.
 * Returns null when there is nothing to take out.
 */
export function urlWithoutLanding(href) {
    const url = new URL(href);
    const names = ['entrant', 'auto', 'notice', 'table', 'entry'];

    if (!names.some((name) => url.searchParams.has(name))) {
        return null;
    }

    for (const name of names) {
        url.searchParams.delete(name);
    }

    return url.toString();
}

/**
 * What following a link does to the live entry page - no Turbo on it (competition toolkit C2), so every link that goes
 * anywhere is a full page load:
 * - 'stay' - this page stays: a new tab or window, a download, a modifier key or another mouse button, a link to a
 *   place on this same page (`#`), a link the browser cannot follow;
 * - 'within' - another live entry page (any round, the event page, a printed or frozen `/live/…` tag URL): it sends
 *   this device's unsent changes too, never asked about;
 * - 'leave' - out of the tool: asked about first while something is unsent.
 *
 * @param {{href: string, target?: string, download?: boolean, button?: number, modifier?: boolean}} link
 * @param {string} currentHref        the page's own URL
 * @param {string} liveUrlTemplate    the live entry route with the round id placeholder (the page's `liveUrl` value)
 * @returns {'stay'|'within'|'leave'}
 */
export function linkNavigation({ href, target = '', download = false, button = 0, modifier = false }, currentHref, liveUrlTemplate) {
    if (download || modifier || button !== 0 || (target !== '' && target !== '_self')) {
        return 'stay';
    }

    let url;
    let current;

    try {
        current = new URL(currentHref);
        url = new URL(href, current);
    } catch (e) {
        return 'stay';
    }

    if (url.protocol !== 'http:' && url.protocol !== 'https:') {
        return 'stay';
    }

    // `#…` on this same page (also a bare `#` - its URL has no hash left): no page load
    if (String(href).includes('#') && url.origin === current.origin && url.pathname === current.pathname && url.search === current.search) {
        return 'stay';
    }

    if (url.origin !== current.origin) {
        return 'leave';
    }

    // Any live entry URL stays in the tool: a round of this locale's route, and every path parseLiveEntryRoute() knows
    // (the event page, the printed name tag, the frozen /live/… tags a scanned tag's "Open their round" links to - H1)
    const livePrefix = new URL(String(liveUrlTemplate).replace(UUID_PLACEHOLDER, ''), current).pathname;

    return (livePrefix !== '/' && url.pathname.startsWith(livePrefix)) || parseLiveEntryRoute(url.pathname) !== null ? 'within' : 'leave';
}
