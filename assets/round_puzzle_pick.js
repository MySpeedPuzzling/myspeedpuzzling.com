/**
 * One tap on a puzzle chip of a `pick` round - the one rule of the judge's card (live_entry_puzzles.js) and the
 * competitor's "Which puzzle(s) did you finish?" (competitor_proposals_view.js, competition toolkit v2 C3): K = 1 picks
 * one (tap again: none); K > 1 adds in solving order, tap again removes, a tap when K are chosen replaces the last.
 * Ids are compared lower-cased as strings. Pure - both harnesses run it.
 */
export function togglePick(choice, id, toSolve) {
    const tapped = String(id).toLowerCase();

    if (choice.includes(tapped)) {
        return choice.filter((picked) => picked !== tapped);
    }

    if (toSolve <= 1) {
        return [tapped];
    }

    return choice.length < toSolve ? [...choice, tapped] : [...choice.slice(0, toSolve - 1), tapped];
}
