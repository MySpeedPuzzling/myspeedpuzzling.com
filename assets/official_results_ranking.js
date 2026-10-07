/**
 * The ranking of official round results in the browser - the rules of Services\OfficialResultsRanking and the order
 * of Query\GetRoundResultEntries, so the results desk re-ranks a live update exactly as the server would
 * (pinned by tests/OfficialResultsRankingParityTest.php):
 * - finished results by seconds, fastest first; then unfinished by pieces placed, most first;
 * - equal results share a rank and the next rank skips ("1, 2, 2, 4"); did not start and no result are unranked;
 * - order: ranked by rank, then did not start, then no result; equal places by table number (none last), then name
 *   (ASCII case-insensitive, like PHP's strcasecmp), then id.
 *
 * A result is the wire format: null | {seconds} | {piecesPlaced} | {didNotStart: true}.
 */

function group(result) {
    if (result && Number.isInteger(result.seconds)) {
        return 0;
    }

    if (result && Number.isInteger(result.piecesPlaced)) {
        return 1;
    }

    if (result && result.didNotStart === true) {
        return 2;
    }

    return 3;
}

export function isRankedResult(result) {
    return group(result) < 2;
}

/**
 * OfficialResultsRanking::compare() - negative, 0 (the same place) or positive.
 */
export function compareResults(a, b) {
    const groupA = group(a);
    const groupB = group(b);

    if (groupA !== groupB) {
        return groupA - groupB;
    }

    if (groupA === 0) {
        return Math.sign(a.seconds - b.seconds);
    }

    if (groupA === 1) {
        return Math.sign(b.piecesPlaced - a.piecesPlaced);
    }

    return 0;
}

// PHP's strcasecmp(): only A-Z are folded; then code point order (UTF-8 byte order)
function compareNames(a, b) {
    const foldedA = Array.from(String(a ?? '').replace(/[A-Z]/g, (letter) => letter.toLowerCase()));
    const foldedB = Array.from(String(b ?? '').replace(/[A-Z]/g, (letter) => letter.toLowerCase()));
    const length = Math.min(foldedA.length, foldedB.length);

    for (let i = 0; i < length; i++) {
        const difference = foldedA[i].codePointAt(0) - foldedB[i].codePointAt(0);

        if (difference !== 0) {
            return Math.sign(difference);
        }
    }

    return Math.sign(foldedA.length - foldedB.length);
}

function compareTables(a, b) {
    const noneA = a === null || a === undefined;
    const noneB = b === null || b === undefined;

    if (noneA || noneB) {
        return (noneA ? 1 : 0) - (noneB ? 1 : 0);
    }

    return Math.sign(a - b);
}

/**
 * OfficialResultsRanking::rank() - the rank of every result (null = unranked), in the input order.
 *
 * @param {Array<object|null>} results
 * @returns {Array<number|null>}
 */
export function rankResults(results) {
    const ranked = results
        .map((result, index) => ({ result, index }))
        .filter(({ result }) => isRankedResult(result))
        .sort((a, b) => compareResults(a.result, b.result) || a.index - b.index);

    const ranks = results.map(() => null);
    let rank = 0;

    ranked.forEach(({ result, index }, position) => {
        if (position === 0 || compareResults(ranked[position - 1].result, result) !== 0) {
            rank = position + 1;
        }

        ranks[index] = rank;
    });

    return ranks;
}

/**
 * The entries of a round ranked and ordered as GetRoundResultEntries::forRound() - new objects with `rank` set.
 *
 * @param {Array<{id: string, displayName: string, tableNumber: number|null, result: object|null}>} entries
 */
export function rankEntries(entries) {
    const ranks = rankResults(entries.map((entry) => entry.result));

    return entries
        .map((entry, index) => ({ ...entry, rank: ranks[index] }))
        .sort((a, b) => compareResults(a.result, b.result)
            || compareTables(a.tableNumber, b.tableNumber)
            || compareNames(a.displayName, b.displayName)
            || (a.id < b.id ? -1 : (a.id > b.id ? 1 : 0)));
}
