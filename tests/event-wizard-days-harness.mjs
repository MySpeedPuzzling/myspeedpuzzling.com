// Runs assets/event_wizard_days.js (a day picked in a wizard row fills the rows below it, spws-demo-ux-leftovers.md
// §7) through its scenarios and prints {scenario: "ok" | error} as JSON - tests/EventWizardDaysScriptTest.php asserts
// every one is "ok".

import assert from 'node:assert/strict';
import { carryDay } from '../assets/event_wizard_days.js';

const D1 = '2027-06-02';
const D2 = '2027-06-03';
const D3 = '2027-06-04';

const scenarios = {
    'a picked day fills the empty rows below it'() {
        assert.deepEqual(carryDay([D1, '', '', ''], 0), { days: [D1, D1, D1, D1], carried: [false, true, true, true] });
    },
    'one pick per day: the first round of the next day refills the carried rows below'() {
        let state = carryDay([D1, '', '', '', '', ''], 0);
        const days = [...state.days];
        days[3] = D2;
        state = carryDay(days, 3, state.carried);
        assert.deepEqual(state.days, [D1, D1, D1, D2, D2, D2]);
        const again = [...state.days];
        again[5] = D3;
        state = carryDay(again, 5, state.carried);
        assert.deepEqual(state.days, [D1, D1, D1, D2, D2, D3]);
        assert.deepEqual(state.carried, [false, true, true, false, true, false]);
    },
    'never a row above, never a day the organiser picked - the carry stops there'() {
        assert.deepEqual(carryDay(['', D2, '', D3, ''], 1).days, ['', D2, D2, D3, '']);
        // Row 2 picked by the organiser earlier (not carried): a pick in row 0 stops before it
        assert.deepEqual(carryDay([D1, '', D2, ''], 0, [false, false, false, false]).days, [D1, D1, D2, '']);
    },
    'a cleared or broken day carries nothing and makes the row the organiser\'s'() {
        assert.deepEqual(carryDay(['', '', ''], 0), { days: ['', '', ''], carried: [false, false, false] });
        assert.deepEqual(carryDay(['02.06.2027', '', ''], 0).days, ['02.06.2027', '', '']);
        assert.deepEqual(carryDay([D1, D1], 5).days, [D1, D1]);
        assert.deepEqual(carryDay(null, 0).days, []);
        assert.deepEqual(carryDay([D1, ''], 1, [false, true]).carried, [false, false]);
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
