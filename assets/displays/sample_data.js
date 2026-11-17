// The display editor's preview data (docs/features/competitions-management/toolkit/D-displays-overlays.md §8.2,
// competition toolkit D6) - pure, no DOM. "Sample": the display's own state (event name, logos, pictures, texts in the
// display's language, the followed round) with a made-up clock per preview phase, 20 sample results and 2 sample
// puzzles. "This display": the state the display itself shows right now (secret puzzles follow the public rule there,
// R §1.5 - the sample puzzles are made up and never real ones).
// Run under node by tests/display-editor-harness.mjs.

import { RESULTS_TYPES } from './schema.js';

export const PREVIEW_PHASES = ['idle', 'scheduled', 'last_seconds', 'running', 'warning', 'times_up', 'stopped'];

const SAMPLE_NAMES = [
    ['Anna Novotná', ['cz']], ['Maximilian von Hohenberg-Schwarzenstein', ['de']], ['Emma Johnson', ['us']],
    ['Lucía Fernández', ['es']], ['Sakura Tanaka', ['jp']], ['Jean-Baptiste Lefèvre', ['fr']],
    ['The Fast Fingers', ['cz', 'sk']], ['Olivia Brown', ['gb']], ['Mateusz Kowalski', ['pl']], ['Sofia Rossi', ['it']],
    ['Lukas Gruber', ['at']], ['Emily Wilson', ['ca']], ['Noah Peeters', ['be']], ['Freya Hansen', ['dk']],
    ['Pieces of Eight', ['nl']], ['Mia Schneider', ['ch']], ['Eva Horváthová', ['sk']], ['Liam Murphy', ['ie']],
    ['Ines Costa', ['pt']], ['Oskar Lindqvist', ['se']],
];

// A made-up 25 × 25 pattern with the three finder squares - a QR element not saved yet has no server-drawn code
export function placeholderQr() {
    const size = 25;
    const rows = [];

    for (let y = 0; y < size; y += 1) {
        let row = '';

        for (let x = 0; x < size; x += 1) {
            const finder = [[0, 0], [size - 7, 0], [0, size - 7]].find(([fx, fy]) => x >= fx && x < fx + 7 && y >= fy && y < fy + 7);

            if (finder) {
                const dx = x - finder[0];
                const dy = y - finder[1];
                const ring = Math.min(dx, dy, 6 - dx, 6 - dy);
                row += ring === 1 ? '0' : '1';
            } else {
                row += (x * 7 + y * 13 + x * y) % 3 === 0 ? '1' : '0';
            }
        }

        rows.push(row);
    }

    return { rows };
}

export function sampleStopwatch(phase, nowMs, minutesLimit) {
    const limitMs = minutesLimit * 60000;
    const base = { roundId: 'sample', minutesLimit, asOfMs: nowMs, stoppedAtMs: null };

    switch (phase) {
        case 'scheduled':
            return { ...base, status: 'scheduled', startedAtMs: nowMs + (2 * 60 + 13) * 1000 };
        case 'last_seconds':
            return { ...base, status: 'scheduled', startedAtMs: nowMs + 7000 };
        case 'running':
            return { ...base, status: 'running', startedAtMs: nowMs - Math.min(limitMs - 60000, (47 * 60 + 12) * 1000) };
        case 'warning':
            return { ...base, status: 'running', startedAtMs: nowMs - (limitMs - (4 * 60 + 30) * 1000) };
        case 'times_up':
            return { ...base, status: 'running', startedAtMs: nowMs - limitMs - 5000 };
        case 'stopped':
            return { ...base, status: 'stopped', startedAtMs: nowMs - 3600000, stoppedAtMs: nowMs - 600000 };
        default:
            return { ...base, status: null, startedAtMs: null };
    }
}

export function sampleResults(nowMs, published) {
    const rows = SAMPLE_NAMES.map(([name, countries], index) => {
        const didNotFinish = index >= 17;

        return {
            key: `sample${index}`,
            rank: index + 1,
            name,
            members: countries.length > 1 ? ['Jana Malá', 'Petr Veselý'] : [],
            countries,
            resultSeconds: didNotFinish ? null : 2400 + index * 97,
            piecesPlaced: didNotFinish ? 480 - index * 10 : null,
            didNotFinish,
            tableNumber: index + 1,
            // The newest finisher glows (highlightLatestSeconds)
            enteredAtMs: nowMs - (index === 13 ? 2000 : (20 - index) * 60000),
        };
    });

    const latest = [...rows].sort((a, b) => b.enteredAtMs - a.enteredAtMs).slice(0, 10);

    return { published, finished: rows.length, total: 24, top: rows, latest };
}

/**
 * The state the preview renders, and the "now" it renders at.
 * Options: mode ('sample' | 'display'), phase (PREVIEW_PHASES), published, revealed, nowMs, design (QR placeholders
 * for QR elements the server has not drawn yet), images (pictures uploaded in this session: path → URL), labels
 * ({round, category, puzzle} for the sample round and puzzles).
 */
export function previewState(base, options) {
    const { mode, phase, published, revealed, nowMs, design, images = {}, labels = {} } = options;
    const state = { ...base, images: { ...(base.images ?? {}), ...images }, qr: { ...(base.qr ?? {}) } };

    for (const element of design?.elements ?? []) {
        if (element.type === 'qr' && !state.qr[element.id]) {
            state.qr[element.id] = placeholderQr();
        }
    }

    if (mode === 'display') {
        return { state, nowMs };
    }

    const round = base.round ?? {
        id: 'sample',
        name: labels.round ?? 'Round 1',
        category: 'solo',
        badge: { bg: '#fe696a', fg: '#ffffff' },
        minutesLimit: 90,
        startsAtMs: nowMs,
        timezone: 'UTC',
        resultsPublished: false,
    };
    const minutesLimit = round.minutesLimit > 0 ? round.minutesLimit : 90;
    const usesResults = (design?.elements ?? []).some((element) => RESULTS_TYPES.includes(element.type));

    state.round = { ...round, minutesLimit, resultsPublished: published };
    state.texts = { ...(base.texts ?? {}), category: base.texts?.category ?? labels.category ?? '' };
    state.stopwatch = sampleStopwatch(PREVIEW_PHASES.includes(phase) ? phase : 'running', nowMs, minutesLimit);
    state.results = usesResults ? sampleResults(nowMs, published) : null;
    state.puzzles = revealed
        ? [
            { name: `${labels.puzzle ?? 'Sample puzzle'} A`, brand: 'Ravensburger', pieces: 500, imageUrl: null },
            { name: `${labels.puzzle ?? 'Sample puzzle'} B`, brand: 'Trefl', pieces: 1000, imageUrl: null },
        ]
        : [];
    state.anyPuzzleRevealed = revealed;

    return { state, nowMs };
}
