// Runs assets/time_entry.js (fast time entry: digits fill from the right, a tapped part is fixed on its own -
// docs/features/competitions-management/toolkit/fast-time-entry.md) through its scenarios and prints
// {scenario: "ok" | error} as JSON - tests/TimeEntryScriptsTest.php asserts every one is "ok".

import assert from 'node:assert/strict';
import { formatDigits, formatTimeEntry, maskTimeEntry, partAt, readTimeEntry, timeEntryText } from '../assets/time_entry.js';
import { parseResultTime } from '../assets/official_results_time.js';

/** Types `keys` one by one into an empty field at its end (the blind path) - the field's text after each key */
function typeBlind(keys) {
    let value = '';
    const shown = [];

    for (const key of keys) {
        const out = maskTimeEntry({ value: value + key, caret: (value + key).length, part: false, previous: value, inserting: true });
        value = out.value;
        shown.push(value);
    }

    return shown;
}

/** A backspace at the end of the field */
function backspace(value) {
    return maskTimeEntry({ value: value.slice(0, -1), caret: value.length - 1, part: false, previous: value, inserting: false }).value;
}

/** Tap on the part at `caret`, then type `keys` over the selection - like the browser would */
function fixPart(value, caret, keys) {
    let [start, end] = partAt(value, caret);
    let text = value;

    for (const key of keys) {
        const edited = text.slice(0, start) + key + text.slice(end);
        const out = maskTimeEntry({ value: edited, caret: start + 1, part: true, previous: text, inserting: true });
        text = out.value;
        start = out.start;
        end = out.end;
    }

    return { value: text, selection: [start, end] };
}

const read = (keys) => readTimeEntry(typeBlind(keys).at(-1) ?? '');

const scenarios = {
    "Jan's cases typed blind fill from the right"() {
        assert.deepEqual(read('3025'), { seconds: 30 * 60 + 25 });
        assert.equal(typeBlind('3025').at(-1), '30:25');
        assert.deepEqual(read('13025'), { seconds: 3600 + 30 * 60 + 25 });
        assert.equal(typeBlind('13025').at(-1), '1:30:25');
        assert.deepEqual(read('5812'), { seconds: 58 * 60 + 12 });
        assert.equal(typeBlind('5812').at(-1), '58:12');
        assert.deepEqual(read('123456'), { seconds: 12 * 3600 + 34 * 60 + 56 });
        assert.equal(typeBlind('123456').at(-1), '12:34:56');
        assert.deepEqual(read('45'), { seconds: 45 });
        assert.equal(typeBlind('45').at(-1), '0:45');
        assert.deepEqual(read('12345'), { seconds: 5025 });
        assert.equal(typeBlind('12345').at(-1), '1:23:45');
    },

    'leading zeros never shift anything'() {
        assert.equal(typeBlind('013025').at(-1), '1:30:25');
        assert.deepEqual(read('013025'), { seconds: 5425 });
        assert.equal(typeBlind('0013025').at(-1), '1:30:25');
        assert.deepEqual(read('0013025'), { seconds: 5425 });
        assert.deepEqual(read('00000013025'), { seconds: 5425 });
        assert.deepEqual(readTimeEntry('0013025'), { seconds: 5425 });
        assert.deepEqual(parseResultTime('0013025'), { seconds: 5425 }, 'the shared parser reads leading zeros the same way');
        assert.deepEqual(typeBlind('0'), [''], 'a zero alone shows nothing yet');
    },

    'the field grows as the judge types'() {
        assert.deepEqual(typeBlind('14723'), ['0:01', '0:14', '1:47', '14:72', '1:47:23']);
        assert.deepEqual(typeBlind('0'), ['']);
        assert.deepEqual(typeBlind('302'), ['0:03', '0:30', '3:02']);
    },

    'an intermediate state is refused only for what it is'() {
        assert.deepEqual(readTimeEntry('14:72'), { error: 'seconds' });
        assert.deepEqual(readTimeEntry('83:45'), { error: 'minutes' }, 'blind digits are h:mm:ss - 8345 is no time');
        assert.deepEqual(readTimeEntry('1:60:00'), { error: 'minutes' });
        assert.deepEqual(readTimeEntry('24:00:00'), { error: 'out_of_range' });
        assert.deepEqual(readTimeEntry('0:00'), { error: 'out_of_range' });
        assert.deepEqual(readTimeEntry(''), { empty: true });
    },

    'more than 6 digits are refused, never shifted'() {
        assert.equal(typeBlind('1234567').at(-1), '123:45:67');
        assert.deepEqual(read('1234567'), { error: 'too_long' });
        assert.deepEqual(readTimeEntry('1234567'), { error: 'too_long' });
    },

    'blind digits read exactly like the shared parser'() {
        // minutes / seconds / too_long are the shared parser's one 'invalid', said more precisely
        const kind = (result) => (result.seconds !== undefined ? result.seconds : (result.empty ? 'empty' : (result.error === 'out_of_range' ? 'range' : 'invalid')));
        const samples = [];

        for (let length = 1; length <= 6; length++) {
            for (let i = 0; i < 400; i++) {
                samples.push(Array.from({ length }, (_, k) => String((i * 7 + k * 13 + length) % 10)).join(''));
            }
        }
        samples.push('0', '00', '59', '60', '959', '960', '5959', '6000', '235959', '240000', '000001', '0000001', '00000059');

        for (const digits of samples) {
            const typed = typeBlind(digits).at(-1) ?? '';
            // zeros alone leave the field empty (nothing typed yet) - the parser calls them out of range
            const mine = typed === '' ? 'range' : kind(readTimeEntry(typed));
            const shared = kind(parseResultTime(digits));

            assert.equal(mine, shared, `${digits} → ${typed}`);
        }
    },

    'backspace removes the last digit and refills'() {
        assert.equal(backspace('1:30:25'), '13:02');
        assert.equal(backspace('13:02'), '1:30');
        assert.equal(backspace('0:05'), '');
        assert.equal(backspace('0:45'), '0:04');
        // a backspace that took only a colon (caret right after it) takes the digit before it
        const colon = maskTimeEntry({ value: '1:3025', caret: 4, part: false, previous: '1:30:25', inserting: false });
        assert.equal(colon.value, '13:25', '1:30:25 without its last 0 - never a dead key');
    },

    'colons, dots and spaces typed on a desk keyboard are ignored'() {
        assert.equal(typeBlind('1:23:45').at(-1), '1:23:45');
        assert.equal(typeBlind('58.12').at(-1), '58:12');
        assert.equal(typeBlind('1 23 45').at(-1), '1:23:45');
    },

    'a pasted time is read as written'() {
        const paste = (text) => maskTimeEntry({ value: text, caret: text.length, part: false, previous: '', inserting: true, paste: true }).value;
        assert.equal(paste('83:45'), '1:23:45', 'mm:ss over an hour keeps working when pasted');
        assert.equal(paste('1:23:45'), '1:23:45');
        assert.equal(paste('0:58:12'), '58:12');
        assert.equal(paste('12345'), '1:23:45');
    },

    'a tapped part is fixed on its own'() {
        // 1:30:25 - minutes should be 45
        const minutes = fixPart('1:30:25', 3, '45');
        assert.equal(minutes.value, '1:45:25');
        assert.deepEqual(minutes.selection, [5, 7], 'after 2 digits the seconds are selected');
        assert.deepEqual(readTimeEntry(minutes.value), { seconds: 3600 + 45 * 60 + 25 });

        // then the seconds too, and a third digit never spills into the minutes
        const seconds = fixPart('1:45:25', 6, '079');
        assert.equal(seconds.value, '1:45:9', 'the last part stays selected: a third digit starts it over');
        assert.deepEqual(fixPart('1:45:25', 6, '79').value, '1:45:79');
        assert.deepEqual(readTimeEntry('1:45:79'), { error: 'seconds' });
        const again = fixPart('1:45:25', 6, '07');
        assert.equal(again.value, '1:45:07');
        assert.deepEqual(again.selection, [5, 7], 'the last part stays selected');

        // hours
        const hours = fixPart('1:30:25', 0, '2');
        assert.equal(hours.value, '2:30:25');
        assert.deepEqual(readTimeEntry(hours.value), { seconds: 2 * 3600 + 30 * 60 + 25 });

        // one digit typed over minutes: 1:5:25 reads 1:05:25 and is tidied when the field is left
        const one = fixPart('1:30:25', 3, '5');
        assert.equal(one.value, '1:5:25');
        assert.deepEqual(readTimeEntry(one.value), { seconds: 3600 + 5 * 60 + 25 });
        assert.equal(formatTimeEntry(readTimeEntry(one.value).seconds), '1:05:25');

        // minutes of a time without hours
        const short = fixPart('58:12', 1, '48');
        assert.equal(short.value, '48:12');
    },

    'a part emptied by backspace reads as zero; the field emptied returns to blind'() {
        const out = maskTimeEntry({ value: '1::25', caret: 2, part: true, previous: '1:30:25', inserting: false });
        assert.equal(out.value, '1::25');
        assert.equal(out.part, true);
        assert.deepEqual(readTimeEntry('1::25'), { seconds: 3625 });

        // a backspace over a colon while a part is edited takes the digit before it - never a dead key
        const colon = maskTimeEntry({ value: '1:4512', caret: 4, part: true, previous: '1:45:12', inserting: false });
        assert.deepEqual(colon, { value: '1:4:12', start: 3, end: 3, part: true });

        // the same in front of an emptied part: the hour before it goes, the shape stays
        const emptied = maskTimeEntry({ value: '1:25', caret: 1, part: true, previous: '1::25', inserting: false });
        assert.deepEqual(emptied, { value: '::25', start: 0, end: 0, part: true });
        assert.deepEqual(readTimeEntry('::25'), { seconds: 25 });

        // any other change of the shape: back to the digits, refilled from the right - never a part mode on it
        const shape = maskTimeEntry({ value: '1:2:5:9', caret: 7, part: true, previous: '1:25', inserting: true });
        assert.equal(shape.part, false);
        assert.equal(shape.value, '12:59');
    },

    'a value set by code shows like typed'() {
        assert.equal(timeEntryText('1:23:45'), '1:23:45');
        assert.equal(timeEntryText('0:58:12'), '58:12', 'a draft stored before this release restores');
        assert.equal(timeEntryText('12345'), '1:23:45');
        assert.equal(timeEntryText(''), '');
        assert.equal(timeEntryText('1:60:00'), '1:60:00', 'not a time - kept as typed, the reading says why');
        assert.equal(formatTimeEntry(45), '0:45');
        assert.equal(formatTimeEntry(3492), '58:12');
        assert.equal(formatTimeEntry(5025), '1:23:45');
        assert.equal(formatDigits('45'), '0:45');
    },
};

const results = {};

for (const [name, run] of Object.entries(scenarios)) {
    try {
        run();
        results[name] = 'ok';
    } catch (error) {
        results[name] = String(error?.stack ?? error);
    }
}

process.stdout.write(JSON.stringify(results, null, 2));
