// Runs assets/event_wizard_days.js (a day picked in a wizard row fills the empty days below it, spws-demo-ux-leftovers.md
// §7) through its scenarios and prints {scenario: "ok" | error} as JSON - tests/EventWizardDaysScriptTest.php asserts
// every one is "ok".

import assert from 'node:assert/strict';
import { carryDay } from '../assets/event_wizard_days.js';

const scenarios = {
    'a picked day fills the empty rows below it'() {
        assert.deepEqual(carryDay(['2027-06-02', '', '', ''], 0), ['2027-06-02', '2027-06-02', '2027-06-02', '2027-06-02']);
    },
    'never a row above, never a typed row - the carry stops there'() {
        assert.deepEqual(carryDay(['', '2027-06-03', '', '2027-06-04', ''], 1), ['', '2027-06-03', '2027-06-03', '2027-06-04', '']);
    },
    'a row changed later carries again into the empty rows only'() {
        const first = carryDay(['2027-06-02', '', '', '', ''], 0);
        const days = [...first.slice(0, 3), '', ''];
        days[2] = '2027-06-03';
        assert.deepEqual(carryDay(days, 2), ['2027-06-02', '2027-06-02', '2027-06-03', '2027-06-03', '2027-06-03']);
    },
    'a cleared or broken day carries nothing'() {
        assert.deepEqual(carryDay(['', '', ''], 0), ['', '', '']);
        assert.deepEqual(carryDay(['02.06.2027', '', ''], 0), ['02.06.2027', '', '']);
        assert.deepEqual(carryDay(['2027-06-02', ''], 5), ['2027-06-02', '']);
        assert.deepEqual(carryDay(null, 0), []);
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
