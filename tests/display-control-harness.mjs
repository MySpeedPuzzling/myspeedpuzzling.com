// Runs assets/displays/control_picker.js under node (competition toolkit v2 L1/L2, displays-standings.md §5.3): the
// message rules (one line, trimmed, 200 characters as the server counts them), the recent chips, the spotlight chips
// (by table, filtered by table or name, "Just finished") and one tap = one write with an optimistic highlight that
// reverts on a refusal. Prints {"<scenario>": "ok" | "<what failed>"} as JSON (DisplayControlScriptTest).

import {
    cleanMessage, entryLabel, filterEntries, JUST_FINISHED, justFinished, MESSAGE_MAX_LENGTH, messageProblem, recentChips,
    sortEntries, SpotlightChoice,
} from '../assets/displays/control_picker.js';

const results = {};

function check(name, run) {
    try {
        const outcome = run();
        results[name] = outcome === undefined || outcome === true ? 'ok' : String(outcome);
    } catch (error) {
        results[name] = `threw ${error?.stack ?? error}`;
    }
}

function equal(actual, expected, message) {
    if (JSON.stringify(actual) !== JSON.stringify(expected)) {
        throw new Error(`${message}: ${JSON.stringify(actual)} !== ${JSON.stringify(expected)}`);
    }
}

check('message: one line, trimmed - the twin of CompetitionDisplayControl::cleanMessage()', () => {
    equal(cleanMessage("  Break -\nnext round\t14:30\u0007 "), 'Break - next round 14:30', 'cleaned');
    equal(cleanMessage('\r\n\r\n'), '', 'only breaks');
    equal(cleanMessage(null), '', 'not a text');
    equal(cleanMessage('<b>bold</b>'), '<b>bold</b>', 'plain text stays as it is');
});

check('message: 200 characters as the server counts them, longer is refused (never cut)', () => {
    equal(MESSAGE_MAX_LENGTH, 200, 'the limit');
    equal(messageProblem('č'.repeat(200)), null, '200 multibyte characters');
    equal(messageProblem('😀'.repeat(200)), null, '200 emoji (surrogate pairs) count once each');
    equal(messageProblem('x'.repeat(201)), 'too_long', '201');
    equal(messageProblem(`  ${'x'.repeat(200)}  `), null, 'trimmed first');
});

check('message: recent chips leave out the one on screen', () => {
    equal(recentChips(['A', 'B', '', null, 'C'], 'B'), ['A', 'C'], 'chips');
    equal(recentChips(null, null), [], 'none');
});

const ENTRIES = [
    { ref: 'participant_round:3', table: 12, name: 'Jane Doe', members: [], finishedAtMs: 3000 },
    { ref: 'participant_round:1', table: 2, name: 'Žofie Nová', members: [], finishedAtMs: null },
    { ref: 'team:9', table: null, name: 'Edge Hunters', members: ['Gina Quick', 'Hugo Slow'], finishedAtMs: 5000 },
    { ref: 'participant_round:2', table: 1, name: 'Anna Fast', members: [], finishedAtMs: 1000 },
    { ref: 'participant_round:4', table: 15, name: 'Ben Steady', members: [], finishedAtMs: 4000 },
];

check('spotlight chips: "12 · Jane Doe", sorted by table, none last', () => {
    equal(entryLabel(ENTRIES[0]), '12 · Jane Doe', 'with a table');
    equal(entryLabel(ENTRIES[2]), 'Edge Hunters', 'without');
    equal(sortEntries(ENTRIES).map((entry) => entry.ref), ['participant_round:2', 'participant_round:1', 'participant_round:3', 'participant_round:4', 'team:9'], 'order');
});

check('spotlight filter: digits = tables starting with them, else names and members without accents', () => {
    const refs = (query) => filterEntries(sortEntries(ENTRIES), query).map((entry) => entry.ref);
    equal(refs('1'), ['participant_round:2', 'participant_round:3', 'participant_round:4'], 'tables 1, 12, 15');
    equal(refs('12'), ['participant_round:3'], 'table 12');
    equal(refs('zofie'), ['participant_round:1'], 'without accents');
    equal(refs('  HUGO '), ['team:9'], 'a member of a team, any case');
    equal(refs(''), ['participant_round:2', 'participant_round:1', 'participant_round:3', 'participant_round:4', 'team:9'], 'empty = all');
    equal(refs('nobody'), [], 'none');
});

check('spotlight on a labelled round: the label instead of the number, found by its folded key, sorted by number', () => {
    const LABELLED = [
        { ref: 'participant_round:5', table: 3, tableLabel: 'A2-1', name: 'Cleo Late', members: [], finishedAtMs: null },
        { ref: 'participant_round:6', table: 1, tableLabel: 'A1-1', name: 'Dan Early', members: [], finishedAtMs: null },
        { ref: 'participant_round:7', table: 2, tableLabel: 'A1 L', name: 'Eva Ahoj', members: [], finishedAtMs: null },
        { ref: 'participant_round:8', table: 40, name: 'Finn Beyond', members: [], finishedAtMs: null },
        { ref: 'team:10', table: 4, tableLabel: '12', name: 'Twelve', members: ['Gus A1'], finishedAtMs: null },
    ];
    const refs = (query) => filterEntries(sortEntries(LABELLED), query).map((entry) => entry.ref);
    equal(entryLabel(LABELLED[0]), 'A2-1 · Cleo Late', 'the label');
    equal(entryLabel(LABELLED[3]), '40 · Finn Beyond', 'a table beyond the list keeps its number');
    equal(entryLabel({ ...LABELLED[0], tableLabel: '' }), '3 · Cleo Late', 'an empty label is no label');
    equal(sortEntries(LABELLED).map((entry) => entry.ref), ['participant_round:6', 'participant_round:7', 'participant_round:5', 'team:10', 'participant_round:8'], 'by number');
    equal(refs('a1'), ['participant_round:6', 'participant_round:7', 'team:10'], 'A1-1, A1 L + a member named "Gus A1"');
    equal(refs('a1 1'), ['participant_round:6'], 'A1-1 typed with a space');
    equal(refs('A1L'), ['participant_round:7'], 'A1 L typed without one');
    equal(refs('1'), ['team:10'], 'digits find a label starting with them, not the hidden numbers 1, 2, 3');
    equal(refs('4'), ['participant_round:8'], 'an unlabelled chip keeps the number rule');
    equal(refs('cleo'), ['participant_round:5'], 'names as before');
});

check('spotlight "Just finished": the newest results first, at most 6', () => {
    equal(JUST_FINISHED, 6, 'six');
    equal(justFinished(ENTRIES).map((entry) => entry.ref), ['team:9', 'participant_round:4', 'participant_round:3', 'participant_round:2'], 'newest first, no result left out');
    const many = Array.from({ length: 10 }, (_, index) => ({ ref: `r${index}`, finishedAtMs: index }));
    equal(justFinished(many).map((entry) => entry.ref), ['r9', 'r8', 'r7', 'r6', 'r5', 'r4'], 'capped');
});

check('one tap = one write, shown at once; a refusal goes back', () => {
    const choice = new SpotlightChoice('participant_round:1');
    equal(choice.tap('participant_round:1'), undefined, 'the current one sends nothing');
    equal(choice.tap('team:9'), 'team:9', 'a tap sends');
    equal(choice.shown, 'team:9', 'highlighted at once');
    equal(choice.settle(false), undefined, 'refused');
    equal(choice.shown, 'participant_round:1', 'back to what it was');
    equal(choice.tap(null), null, 'Clear sends null');
    equal(choice.settle(true), undefined, 'taken');
    equal(choice.shown, null, 'cleared');
});

check('taps while a write is on its way: the last one goes next, a refresh never overrides a pending tap', () => {
    const choice = new SpotlightChoice(null);
    equal(choice.tap('a'), 'a', 'first');
    equal(choice.tap('b'), undefined, 'waits');
    equal(choice.tap('c'), undefined, 'waits');
    equal(choice.shown, 'c', 'the last tap shown');
    choice.sync('x');
    equal(choice.shown, 'c', 'a refresh meanwhile changes nothing');
    equal(choice.settle(true), 'c', 'the last tap goes next');
    equal(choice.settle(true), undefined, 'done');
    equal(choice.shown, 'c', 'confirmed');
    choice.sync('d');
    equal(choice.shown, 'd', 'a refresh while idle is taken');
    // Back to the confirmed one before the write returned - nothing more to send
    equal(choice.tap('e'), 'e', 'send');
    equal(choice.tap('d'), undefined, 'queued');
    equal(choice.settle(false), undefined, 'refused, and the queued one is what is shown anyway');
    equal(choice.shown, 'd', 'shown');
});

process.stdout.write(JSON.stringify(results));
