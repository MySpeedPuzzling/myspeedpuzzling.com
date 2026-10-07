// Runs the results desk's pure helpers (assets/official_results_qualification.js and
// assets/official_results_pending_changes.js) for the cases tests/OfficialResultsDeskHelpersTest.php hands over on
// stdin, and prints one result per case as JSON.

import { readFileSync } from 'node:fs';
import { bestOfEachCountry, qualificationDiff, seatAdvanced, topN } from '../assets/official_results_qualification.js';
import { PendingChanges } from '../assets/official_results_pending_changes.js';

const cases = JSON.parse(readFileSync(0, 'utf8'));

function runPending(steps) {
    const pending = new PendingChanges();
    let counter = 0;
    const newId = () => `c${++counter}`;
    let lastTaken = [];
    const output = [];

    for (const step of steps) {
        switch (step.op) {
            case 'set':
                pending.set(step.ref, step.field, step.to, step.server);
                break;
            case 'take': {
                const taken = pending.take(newId, step.limit ?? 500);
                // `settle` answers the last request that sent something
                lastTaken = taken.length > 0 ? taken : lastTaken;
                output.push({ taken });
                break;
            }
            case 'settle':
                pending.settle({ clientChangeId: lastTaken[step.index].clientChangeId, status: step.status, reason: step.reason ?? null, current: step.current ?? null, message: step.message ?? null, enteredBy: step.enteredBy ?? null });
                break;
            case 'retryInFlight':
                pending.retryInFlight();
                break;
            case 'failInFlight':
                pending.failInFlight(step.message);
                break;
            case 'keepMine':
                pending.keepMine(step.ref, step.field);
                break;
            case 'discard':
                pending.discard(step.ref, step.field);
                break;
            case 'retry':
                pending.retry(step.ref, step.field);
                break;
            case 'snapshot':
                output.push({
                    cells: pending.list().map((cell) => ({ ref: cell.ref, field: cell.field, base: cell.base, to: cell.to, status: cell.status, conflict: cell.conflict, message: cell.message })),
                    shown: step.shown ? pending.value(step.shown.ref, step.shown.field, step.shown.server) : undefined,
                    counts: pending.counts(),
                });
                break;
            default:
                throw new Error(`Unknown step ${step.op}`);
        }
    }

    return output;
}

process.stdout.write(JSON.stringify(cases.map((testCase) => {
    switch (testCase.fn) {
        case 'topN':
            return topN(testCase.entries, testCase.count);
        case 'bestOfEachCountry':
            return bestOfEachCountry(testCase.entries, testCase.perCountry);
        case 'qualificationDiff':
            return qualificationDiff(testCase.entries, testCase.selected, testCase.keepOthers);
        case 'seatAdvanced':
            return seatAdvanced(testCase.targetEntries, testCase.refs, testCase.slowestFirst ?? false);
        case 'pending':
            return runPending(testCase.steps);
        default:
            throw new Error(`Unknown case ${testCase.fn}`);
    }
})));
