// Runs the completeness check's part of the online review - assets/online/review_check.js (Phase 3) - through its
// scenarios and prints {scenario: "ok" | error} as JSON. tests/OnlineReviewCheckScriptTest.php asserts every one is "ok".

import assert from 'node:assert/strict';
import { CHECK_RANKS, checkBadge, gapBoxes, reviewOrder } from '../assets/online/review_check.js';

function entry(name, status, check) {
    const row = { participantId: name, name, status };

    if (check !== undefined) {
        row.check = check;
    }

    return row;
}

const check = (state, extra = {}) => ({ state, confidence: 0.8, gaps: [], reason: 'why', imageWidth: 1920, imageHeight: 1080, ...extra });

const scenarios = {
    async 'badge: every state has its text and title, a failed check reads "Not checked"'() {
        assert.deepEqual(checkBadge(check('complete')), { state: 'complete', text: 'check_complete', title: 'check_title', tone: 'complete' });
        assert.deepEqual(checkBadge(check('gaps')), { state: 'gaps', text: 'check_gaps', title: 'check_title', tone: 'gaps' });
        assert.deepEqual(checkBadge(check('unsure')), { state: 'unsure', text: 'check_unsure', title: 'check_title', tone: 'unsure' });
        assert.deepEqual(checkBadge({ state: 'pending' }), { state: 'pending', text: 'check_pending', title: 'check_pending_title', tone: 'pending' });
        assert.deepEqual(checkBadge({ state: 'failed', reason: 'finish_unavailable' }), { state: 'failed', text: 'check_pending', title: 'check_failed_title', tone: 'pending' });
    },

    async 'badge: none without a check - the check off, a photo that never arrived, anything unknown'() {
        assert.equal(checkBadge(undefined), null);
        assert.equal(checkBadge(null), null);
        assert.equal(checkBadge({}), null);
        assert.equal(checkBadge({ state: 'maybe' }), null);
        assert.equal(checkBadge('gaps'), null);
    },

    async 'boxes: the gaps as percentages of the photo the check measured'() {
        const boxes = gapBoxes(check('gaps', { gaps: [{ x: 960, y: 540, w: 96, h: 54 }, { x: 0, y: 0, w: 192, h: 108 }] }));

        assert.deepEqual(boxes, [
            { left: 50, top: 50, width: 5, height: 5, centerX: 52.5, centerY: 52.5 },
            { left: 0, top: 0, width: 10, height: 10, centerX: 5, centerY: 5 },
        ]);
    },

    async 'boxes: scaled to any shown size - the percentages do not depend on it'() {
        const gaps = [{ x: 100, y: 200, w: 30, h: 40 }];
        const big = gapBoxes(check('gaps', { gaps, imageWidth: 1000, imageHeight: 800 }));
        // The same photo shown at 320 × 256: the box lands on the same spot
        const shownWidth = 320;
        const shownHeight = 256;

        assert.deepEqual(big, [{ left: 10, top: 25, width: 3, height: 5, centerX: 11.5, centerY: 27.5 }]);
        assert.equal(big[0].left / 100 * shownWidth, 100 * shownWidth / 1000);
        assert.equal(big[0].top / 100 * shownHeight, 200 * shownHeight / 800);
    },

    async 'boxes: the natural size when the check gives none, nothing without any'() {
        const noSize = check('gaps', { gaps: [{ x: 64, y: 36, w: 64, h: 36 }], imageWidth: null, imageHeight: null });

        assert.deepEqual(gapBoxes(noSize, 640, 360), [{ left: 10, top: 10, width: 10, height: 10, centerX: 15, centerY: 15 }]);
        assert.deepEqual(gapBoxes(noSize), []);
        assert.deepEqual(gapBoxes(noSize, 0, 360), []);
    },

    async 'boxes: clipped to the photo, nonsense left out'() {
        const boxes = gapBoxes(check('gaps', {
            imageWidth: 100,
            imageHeight: 100,
            gaps: [
                { x: -10, y: 90, w: 30, h: 30 },
                { x: 120, y: 10, w: 10, h: 10 },
                { x: 10, y: 10, w: 0, h: 10 },
                { x: 'a', y: 10, w: 10, h: 10 },
                null,
                { x: 33, y: 33, w: 33.333, h: 1 },
            ],
        }));

        assert.deepEqual(boxes, [
            { left: 0, top: 90, width: 20, height: 10, centerX: 10, centerY: 95 },
            { left: 33, top: 33, width: 33.333, height: 1, centerX: 49.667, centerY: 33.5 },
        ]);
    },

    async 'boxes: only a "gaps" check draws any'() {
        const gaps = [{ x: 1, y: 1, w: 10, h: 10 }];

        for (const state of ['complete', 'unsure', 'pending', 'failed']) {
            assert.deepEqual(gapBoxes(check(state, { gaps })), [], state);
        }

        assert.deepEqual(gapBoxes(null), []);
        assert.deepEqual(gapBoxes(check('gaps', { gaps: 'x' })), []);
    },

    async 'order: off - the list as it came'() {
        const list = [entry('Ada', 'needs_look', check('complete')), entry('Bo', 'needs_look', check('gaps'))];

        assert.deepEqual(reviewOrder(list, false).map((row) => row.name), ['Ada', 'Bo']);
        assert.deepEqual(reviewOrder(list, undefined).map((row) => row.name), ['Ada', 'Bo']);
        assert.notEqual(reviewOrder(list, false), list, 'a copy - the page sorts it again');
    },

    async 'order: gaps, then not sure, then not checked, then looks complete - by name within each'() {
        const list = [
            entry('Ada', 'needs_look', check('complete')),
            entry('Bo', 'needs_look', check('pending')),
            entry('Cy', 'needs_look', check('gaps')),
            entry('Di', 'unfinished', null),
            entry('Ed', 'needs_look', check('unsure')),
            entry('Fa', 'needs_look', check('gaps')),
            entry('Gu', 'needs_look', check('failed')),
            entry('Hu', 'no_finish'),
        ];

        assert.deepEqual(reviewOrder(list, true).map((row) => row.name), ['Cy', 'Fa', 'Ed', 'Bo', 'Di', 'Gu', 'Hu', 'Ada']);
    },

    async 'order: a confirmed participant keeps its place, the others fill the rest'() {
        const list = [
            entry('Ada', 'confirmed', check('gaps')),
            entry('Bo', 'needs_look', check('complete')),
            entry('Cy', 'needs_look', check('unsure')),
            entry('Di', 'confirmed', check('complete')),
            entry('Ed', 'needs_look', check('gaps')),
        ];
        const ordered = reviewOrder(list, true).map((row) => row.name);

        assert.deepEqual(ordered, ['Ada', 'Ed', 'Cy', 'Di', 'Bo']);
        assert.equal(ordered.length, list.length);
    },

    async 'order: confirming one moves only the open ones around it'() {
        const before = [
            entry('Ada', 'needs_look', check('complete')),
            entry('Bo', 'needs_look', check('gaps')),
            entry('Cy', 'needs_look', check('unsure')),
        ];
        const after = before.map((row) => (row.name === 'Bo' ? { ...row, status: 'confirmed' } : row));

        assert.deepEqual(reviewOrder(before, true).map((row) => row.name), ['Bo', 'Cy', 'Ada']);
        // Bo keeps its own place in the list (by name), the open ones around it stay "needs a look" first
        assert.deepEqual(reviewOrder(after, true).map((row) => row.name), ['Cy', 'Bo', 'Ada']);
    },

    async 'order: the ranks are the brief\'s - gaps, unsure, not checked, complete'() {
        assert.ok(CHECK_RANKS.gaps < CHECK_RANKS.unsure);
        assert.ok(CHECK_RANKS.unsure < CHECK_RANKS.pending);
        assert.equal(CHECK_RANKS.pending, CHECK_RANKS.failed);
        assert.ok(CHECK_RANKS.pending < CHECK_RANKS.complete);
        assert.deepEqual(reviewOrder([], true), []);
        assert.deepEqual(reviewOrder(null, true), []);
    },
};

const results = {};

for (const [name, run] of Object.entries(scenarios)) {
    try {
        await run();
        results[name] = 'ok';
    } catch (error) {
        results[name] = String(error?.stack ?? error);
    }
}

process.stdout.write(JSON.stringify(results));
