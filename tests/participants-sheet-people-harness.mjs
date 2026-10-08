// Runs the People tab's modules (assets/participants_sheet/people_paste.js, registration_actions.js, the pure helpers
// of views/people_view.js and - suite `view`, in jsdom - the People grid, the person editor and the phone list on the
// real model, grid and preview dialog) under node for tests/ParticipantsSheetPeopleScriptsTest.php: the cases come on
// stdin as JSON, one result per case is printed.
//
// - {"suite": "<name>"} runs tests/participants-sheet-people/<name>.mjs (node:assert tests) and answers
//   {"suite", "passed", "failures": [{"name", "message"}]};
// - {"fn": "..."} cases answer one value the PHP test asserts itself.
//
// By hand: echo '[{"suite":"paste"}]' | node tests/participants-sheet-people-harness.mjs

import { readFileSync } from 'node:fs';
import { SheetModel } from '../assets/participants_sheet/sheet_model.js';
import { wireGroups } from '../assets/participants_sheet/sheet_changes.js';
import { namePasteAction, planNamePaste } from '../assets/participants_sheet/people_paste.js';
import { allowedActions, firstInLine, registrationCounts, waitlistPositions } from '../assets/participants_sheet/registration_actions.js';
import { parseClipboardText } from '../assets/participants_sheet/tsv.js';

const SUITES = ['paste', 'registration', 'filters', 'view'];

async function runSuite(name) {
    if (!SUITES.includes(name)) {
        throw new Error(`Unknown suite ${name}`);
    }

    const tests = [];
    const register = (testName, fn) => tests.push({ name: testName, fn });
    const module = await import(`./participants-sheet-people/${name}.mjs`);
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
        case 'namePaste': {
            // An Excel block (CRLF) pasted onto the new-person row: the plan and the wire format of the confirmed action
            let counter = 0;
            const model = new SheetModel(testCase.state, { now: () => 0 });
            const countries = { cz: 'Czechia', us: 'United States' };
            const readCountry = (text) => {
                const value = text.trim().toLowerCase();

                if (value === '') {
                    return null;
                }

                return value in countries ? value : (Object.entries(countries).find(([, label]) => label.toLowerCase() === value)?.[0]);
            };
            const plan = planNamePaste(model, parseClipboardText(testCase.text), { readCountry, headerNames: ['Name'] });
            const { action } = namePasteAction(model, plan, testCase.ticks ?? {}, { newId: () => `id${++counter}`, countries: new Set(Object.keys(countries)) });

            return {
                lines: plan.lines.map((line) => ({ name: line.name, status: line.status, country: line.country, externalId: line.externalId, matches: line.matches })),
                counts: plan.counts,
                groups: wireGroups(action.groups),
                inverse: wireGroups(action.inverse),
            };
        }
        case 'registration': {
            const people = testCase.people;

            return {
                actions: Object.fromEntries(people.map((person) => [person.id, allowedActions(person, { checkIn: testCase.checkIn ?? true })])),
                positions: Object.fromEntries(waitlistPositions(people)),
                counts: registrationCounts(people, testCase.capacity ?? null),
                first: firstInLine(people, testCase.capacity ?? null)?.id ?? null,
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
