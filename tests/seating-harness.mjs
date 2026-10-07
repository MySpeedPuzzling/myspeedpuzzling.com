// Runs assets/seating.js (the pure half of the seating page) for the cases tests/SeatingJsTest.php hands over on
// stdin, and prints each result as JSON. Entries come as arrays and become the Map the module expects.

import { readFileSync } from 'node:fs';
import * as seating from '../assets/seating.js';

const byRef = (entries) => new Map((entries ?? []).map((entry) => [entry.ref, entry]));

const operations = {
    split: ({ entries, locale }) => seating.splitByTable(entries, seating.nameComparator(locale)),
    merge: ({ unseated, seated, entries }) => seating.mergeOrder(unseated, seated, byRef(entries)),
    move: ({ unseated, seated, ref, direction }) => seating.moveInLists(unseated, seated, ref, direction),
    renumberStart: ({ seated, entries }) => seating.renumberStart(seated, byRef(entries)),
    renumber: ({ unseated, seated, entries, start }) => seating.renumberAssignments(unseated, seated, byRef(entries), start),
    seatRest: ({ unseated, entries }) => seating.seatRestAssignments(unseated, byRef(entries)),
    swap: ({ a, b }) => seating.swapAssignments(a, b),
    takeOver: ({ entry, number, holder }) => seating.takeOverAssignments(entry, number, holder),
    clear: ({ entries }) => seating.clearAssignments(byRef(entries)),
    proposal: ({ rows, entries }) => seating.proposalAssignments(rows, byRef(entries)),
    covers: ({ rows, entries }) => seating.proposalCoversEntrants(rows, byRef(entries)),
    undo: ({ assignments, entries }) => seating.undoAssignments(assignments, seating.numbersOf(byRef(entries))),
    withFrom: ({ assignments, entries }) => seating.withFrom(assignments, byRef(entries)),
    holder: ({ entries, number, except }) => seating.holderOf(byRef(entries), number, except)?.ref ?? null,
    parse: ({ text }) => seating.parseTableNumber(text),
    typed: ({ seen, current, typed }) => seating.typedNumberWrite(seen, current, typed),
    seen: ({ attribute, current }) => seating.seenNumber(attribute, current),
    matches: ({ entry, query }) => seating.matchesQuery(entry, query),
    fold: ({ text }) => seating.fold(text),
};

const cases = JSON.parse(readFileSync(0, 'utf8'));

process.stdout.write(JSON.stringify(cases.map((testCase) => operations[testCase.op](testCase))));
