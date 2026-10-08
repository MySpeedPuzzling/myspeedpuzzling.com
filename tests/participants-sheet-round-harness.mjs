// Runs the participants spreadsheet's round-tab modules (assets/participants_sheet/{sheet_results,round_paste,
// round_common}.js - stream D) under node for tests/ParticipantsSheetRoundScriptsTest.php: the cases come on stdin as
// JSON, one result per case is printed.
//
// - {"suite": "<name>"} runs tests/participants-sheet-round/<name>.mjs (node:assert tests) and answers
//   {"suite", "passed", "failures": [{"name", "message"}]};
// - {"fn": "..."} cases answer one value the PHP test asserts itself (the facts worth reading in PHP).
//
// By hand: echo '[{"suite":"results"}]' | node tests/participants-sheet-round-harness.mjs

import { readFileSync } from 'node:fs';
import { parseResultInput } from '../assets/participants_sheet/sheet_results.js';
import { buildTeamPasteAction, planTeamPaste } from '../assets/participants_sheet/round_paste.js';
import { wireGroups } from '../assets/participants_sheet/sheet_changes.js';
import { SheetModel } from '../assets/participants_sheet/sheet_model.js';
import { parseClipboardText } from '../assets/participants_sheet/tsv.js';

const SUITES = ['results', 'paste', 'common'];

async function runSuite(name) {
    if (!SUITES.includes(name)) {
        throw new Error(`Unknown suite ${name}`);
    }

    const tests = [];
    const register = (testName, fn) => tests.push({ name: testName, fn });
    const module = await import(`./participants-sheet-round/${name}.mjs`);
    module.default(register);

    const failures = [];
    let passed = 0;

    for (const { name: testName, fn } of tests) {
        try {
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
        case 'parseResultInput':
            return testCase.inputs.map((input) => parseResultInput(input, testCase.options ?? {}));
        case 'pasteTeams': {
            // A pasted block (CRLF text, as Excel puts it on the clipboard) → the groups the sheet sends
            let counter = 0;
            const newId = () => `id${++counter}`;
            const model = new SheetModel(testCase.state, { now: () => 0 });
            const block = parseClipboardText(testCase.text);
            const columns = block[0].map((_, index) => (index === 0 ? 'name' : 'member'));
            const plan = planTeamPaste(model, testCase.roundId, block, { columns });
            const action = buildTeamPasteAction(model, testCase.roundId, plan, testCase.selection ?? {}, { newId });

            return {
                lines: plan.lines.map((line) => ({ match: line.match, teamId: line.teamId, name: line.name, members: line.members.map((member) => member.status) })),
                counts: plan.counts,
                groups: wireGroups(action.groups),
                errors: action.errors.map((error) => error.reason),
            };
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
