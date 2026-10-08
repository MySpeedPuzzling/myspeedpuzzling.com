// Runs the participants spreadsheet's core modules (assets/participants_sheet/*.js - stream C) under node for
// tests/ParticipantsSheetCoreScriptsTest.php: the cases come on stdin as JSON, one result per case is printed.
//
// - {"suite": "<name>"} runs tests/participants-sheet-core/<name>.mjs (node:assert tests) and answers
//   {"suite", "passed", "failures": [{"name", "message"}]};
// - {"fn": "..."} cases answer one value the PHP test asserts itself (the protocol facts worth reading in PHP).
//
// By hand: echo '[{"suite":"queue"}]' | node tests/participants-sheet-core-harness.mjs

import { readFileSync } from 'node:fs';
import { parseClipboardText, toTsv } from '../assets/participants_sheet/tsv.js';
import { nextAction } from '../assets/participants_sheet/grid_keys.js';
import { SheetModel } from '../assets/participants_sheet/sheet_model.js';
import { newTeamRow, invertGroups, wireGroups } from '../assets/participants_sheet/sheet_changes.js';

const SUITES = ['tsv', 'keys', 'model', 'changes', 'queue', 'undo', 'live', 'grid', 'people', 'controller', 'perf'];

async function runSuite(name) {
    if (!SUITES.includes(name)) {
        throw new Error(`Unknown suite ${name}`);
    }

    const tests = [];
    const register = (testName, fn) => tests.push({ name: testName, fn });
    const module = await import(`./participants-sheet-core/${name}.mjs`);
    module.default(register);

    const failures = [];
    let passed = 0;

    for (const { name: testName, fn } of tests) {
        try {
            // A test waiting for something that never comes fails instead of hanging the run
            let timer;
            await Promise.race([
                Promise.resolve().then(fn),
                new Promise((resolve, reject) => {
                    timer = setTimeout(() => reject(new Error('Timed out after 5 s')), 5000);
                }),
            ]).finally(() => clearTimeout(timer));
            passed++;
        } catch (error) {
            failures.push({ name: testName, message: String(error?.stack ?? error).split('\n').slice(0, 6).join('\n') });
        }
    }

    return { suite: name, passed, failures };
}

function runFn(testCase) {
    switch (testCase.fn) {
        case 'parseClipboardText':
            return parseClipboardText(testCase.text);
        case 'toTsv':
            return toTsv(testCase.rows);
        case 'nextAction':
            return nextAction(testCase.state, testCase.event);
        case 'newTeamRow': {
            // The wire format of "type a pair into the new row" and its undo
            let counter = 0;
            const newId = () => `id${++counter}`;
            const model = new SheetModel(testCase.state, { now: () => 0 });
            const action = newTeamRow(model, testCase.roundId, testCase.row, { newId });

            return { groups: wireGroups(action.groups), inverse: wireGroups(action.inverse), errors: action.errors.map((error) => error.reason) };
        }
        case 'invertGroups': {
            let counter = 0;
            const model = new SheetModel(testCase.state, { now: () => 0 });

            return wireGroups(invertGroups(testCase.groups, model, () => `inv${++counter}`));
        }
        default:
            throw new Error(`Unknown case ${testCase.fn}`);
    }
}

const cases = JSON.parse(readFileSync(0, 'utf8'));
const results = [];

for (const testCase of cases) {
    results.push(testCase.suite !== undefined ? await runSuite(testCase.suite) : runFn(testCase));
}

process.stdout.write(JSON.stringify(results));
