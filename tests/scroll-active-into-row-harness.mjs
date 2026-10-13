// Runs assets/navigation/scroll_active_into_row.js (the leaderboards' pill row and the section chips bring their current
// pill into view - docs/features/navigation COM-02) for tests/ScrollActiveIntoRowScriptTest.php: fake elements with the
// layout numbers the helper reads; prints one JSON object.

import { scrollActiveIntoRow } from '../assets/navigation/scroll_active_into_row.js';

function row(clientWidth) {
    return { clientWidth, scrollLeft: 0 };
}

const results = {};

// Fits: nothing moves
{
    const r = row(360);
    results.fits = { moved: scrollActiveIntoRow(r, { offsetLeft: 200, offsetWidth: 120 }), scrollLeft: r.scrollLeft };
}

// Past the right edge: centred in the row
{
    const r = row(360);
    results.pastTheEdge = { moved: scrollActiveIntoRow(r, { offsetLeft: 400, offsetWidth: 120 }), scrollLeft: r.scrollLeft };
}

// No row or no current pill
results.missing = { noActive: scrollActiveIntoRow(row(360), null), noRow: scrollActiveIntoRow(null, { offsetLeft: 0, offsetWidth: 1 }) };

process.stdout.write(JSON.stringify(results));
