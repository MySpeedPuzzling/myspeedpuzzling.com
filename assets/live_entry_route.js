/**
 * Which live entry URL the page was opened at (competition toolkit C5, docs/features/competitions-management/toolkit/
 * C-judge-page.md §1.6, R-review-and-plan.md §1.3). The offline copy of the page (`live_results_shell`) is answered by
 * the service worker in place of any live entry URL whose network request failed, so it learns from its own `location`
 * what was asked for: a round, an event, or one of the printed QR tags. Pure - run by tests/live-entry-shell-harness.mjs.
 *
 * Paths (the router's, pinned by the harness; ids are lower-cased, a trailing slash is tolerated, the host is not
 * looked at):
 * - `live_results`        `/zadavani-vysledku/{roundId}`, `/{en|es|ja|fr|de}/live-results/{roundId}`
 *                         → `{kind: 'round', roundId, locale}`
 * - `live_results_event`  `/zadavani-vysledku-udalosti/{competitionId}`, `/{en|es|ja|fr|de}/live-results/event/{competitionId}`
 *                         → `{kind: 'event', competitionId, locale}`
 * - the printed name tag  `/{locale}/live/{competitionId}/p/{participantId}` (unchanged, always with a locale)
 *                         → `{kind: 'participant', competitionId, id: participantId, locale}`
 * - the frozen new tags (R §1.3; printed without a locale, the routes accept one too):
 *   `/live/{competitionId}/g/{lineupId}`  → `{kind: 'lineup', competitionId, id, locale}`
 *   `/live/{competitionId}/t/{1-9999}`    → `{kind: 'table', competitionId, id: <number>, locale}`
 *   `/live/{competitionId}/e/{entryId}`   → `{kind: 'entry', competitionId, id, locale}`
 *   (`locale` null when the path has none). Only parsed here - toolkit C8 resolves them.
 * Anything else → null.
 */

const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
const LOCALES = 'en|cs|es|ja|fr|de';
// cs has its own words for the live entry routes, the other locales share the English path under their prefix
const PREFIXED = 'en|es|ja|fr|de';

const ROUTES = [
    { pattern: new RegExp(`^/zadavani-vysledku/(${UUID})/?$`, 'i'), build: (m) => ({ kind: 'round', roundId: m[1], locale: 'cs' }) },
    { pattern: new RegExp(`^/(${PREFIXED})/live-results/(${UUID})/?$`, 'i'), build: (m) => ({ kind: 'round', roundId: m[2], locale: m[1] }) },
    { pattern: new RegExp(`^/zadavani-vysledku-udalosti/(${UUID})/?$`, 'i'), build: (m) => ({ kind: 'event', competitionId: m[1], locale: 'cs' }) },
    { pattern: new RegExp(`^/(${PREFIXED})/live-results/event/(${UUID})/?$`, 'i'), build: (m) => ({ kind: 'event', competitionId: m[2], locale: m[1] }) },
    { pattern: new RegExp(`^/(${LOCALES})/live/(${UUID})/p/(${UUID})/?$`, 'i'), build: (m) => ({ kind: 'participant', competitionId: m[2], id: m[3], locale: m[1] }) },
    { pattern: new RegExp(`^(?:/(${LOCALES}))?/live/(${UUID})/g/(${UUID})/?$`, 'i'), build: (m) => ({ kind: 'lineup', competitionId: m[2], id: m[3], locale: m[1] ?? null }) },
    { pattern: new RegExp(`^(?:/(${LOCALES}))?/live/(${UUID})/t/([1-9]\\d{0,3})/?$`, 'i'), build: (m) => ({ kind: 'table', competitionId: m[2], id: parseInt(m[3], 10), locale: m[1] ?? null }) },
    { pattern: new RegExp(`^(?:/(${LOCALES}))?/live/(${UUID})/e/(${UUID})/?$`, 'i'), build: (m) => ({ kind: 'entry', competitionId: m[2], id: m[3], locale: m[1] ?? null }) },
];

/**
 * @param {string} href  a URL (any host) or a path
 * @returns {object|null}
 */
export function parseLiveEntryRoute(href) {
    let pathname;

    try {
        pathname = new URL(String(href ?? ''), 'https://localhost').pathname;
    } catch (e) {
        return null;
    }

    for (const { pattern, build } of ROUTES) {
        const match = pathname.match(pattern);

        if (match !== null) {
            const route = build(match);

            for (const key of ['roundId', 'competitionId', 'id', 'locale']) {
                if (typeof route[key] === 'string') {
                    route[key] = route[key].toLowerCase();
                }
            }

            return route;
        }
    }

    return null;
}
