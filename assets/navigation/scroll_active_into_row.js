/**
 * A sideways-scrolling row of pills (the leaderboards' pill row, the section chips - docs/features/navigation COM-02)
 * brings its current pill into view on load: centred when it lies past the row's right edge, the row only - the page
 * never moves (no scrollIntoView, which would scroll the document too). The row must be the pill's offsetParent
 * (position: relative). No DOM globals: tests/scroll-active-into-row-harness.mjs runs it under node (ScrollActiveIntoRowScriptTest).
 */
export function scrollActiveIntoRow(row, active) {
    if (!row || !active) {
        return false;
    }

    const overflow = active.offsetLeft + active.offsetWidth - row.clientWidth;

    if (overflow <= 0) {
        return false;
    }

    row.scrollLeft = active.offsetLeft - (row.clientWidth - active.offsetWidth) / 2;

    return true;
}
