// The competitor list's search (templates/competitor_page/list.html.twig, competitor_list_controller.js): the server
// renders every row's folded name(s) and shown #code into `data-search` (SearchText::fold()), the browser folds only
// what was typed - every typed word must be in a row's text. Pure, pinned by tests/CompetitorListScriptTest.php.

import { foldSearchText } from './search_fold.js';

/**
 * The typed words, folded like the server folds the rows ("#code" keeps its "#")
 *
 * @param {string} query
 * @returns {string[]}
 */
export function competitorListTokens(query) {
    return foldSearchText(query ?? '').split(' ').filter((token) => token !== '');
}

/**
 * @param {string} search a row's `data-search`
 * @param {string[]} tokens competitorListTokens()
 */
export function competitorListRowMatches(search, tokens) {
    return tokens.every((token) => (search ?? '').includes(token));
}

/**
 * Which rows show and how many per section - sections: list of lists of `data-search` texts
 *
 * @param {string[][]} sections
 * @param {string} query
 * @returns {{ shown: boolean[][], counts: number[], total: number }}
 */
export function filterCompetitorList(sections, query) {
    const tokens = competitorListTokens(query);
    const shown = sections.map((rows) => rows.map((search) => competitorListRowMatches(search, tokens)));
    const counts = shown.map((rows) => rows.filter(Boolean).length);

    return { shown, counts, total: counts.reduce((sum, count) => sum + count, 0) };
}
