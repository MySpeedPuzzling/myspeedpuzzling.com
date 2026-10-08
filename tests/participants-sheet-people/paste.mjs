// Adding people by pasting names (assets/participants_sheet/people_paste.js): `name`, `name ⇥ country`,
// `name ⇥ country ⇥ external id` per line, matched by the name key against the active and the removed people.
import assert from 'node:assert/strict';
import { SheetModel } from '../../assets/participants_sheet/sheet_model.js';
import { wireGroups } from '../../assets/participants_sheet/sheet_changes.js';
import { parseClipboardText } from '../../assets/participants_sheet/tsv.js';
import {
    LINE_DUPLICATE,
    LINE_EXISTING,
    LINE_HEADER,
    LINE_INVALID,
    LINE_NEW,
    LINE_REMOVED,
    lineOfError,
    namePasteAction,
    planNamePaste,
} from '../../assets/participants_sheet/people_paste.js';
import { ids, smallState } from '../participants-sheet-core/fixture.mjs';

const COUNTRIES = { cz: 'Czechia', us: 'United States', ca: 'Canada' };

function readCountry(text) {
    const value = text.trim().toLowerCase();

    if (value === '') {
        return null;
    }

    if (value in COUNTRIES) {
        return value;
    }

    return Object.entries(COUNTRIES).find(([, label]) => label.toLowerCase() === value)?.[0];
}

const model = () => new SheetModel(smallState(), { now: () => 0 });
const plan = (m, text, options = {}) => planNamePaste(m, parseClipboardText(text), { readCountry, headerNames: ['Name', 'Country'], ...options });
const statuses = (p) => p.lines.map((line) => [line.name, line.status]);

export default function (test) {
    test('names only: one new person per line, ticked, a group each', () => {
        const m = model();
        const p = plan(m, 'Robin Example\r\nSam Placeholder\r\n');
        assert.deepEqual(statuses(p), [['Robin Example', LINE_NEW], ['Sam Placeholder', LINE_NEW]]);
        assert.ok(p.lines.every((line) => line.tick));

        const { action, lineOfGroup, peopleIds } = namePasteAction(m, p, {}, { newId: ids(), countries: new Set(Object.keys(COUNTRIES)) });
        assert.deepEqual(wireGroups(action.groups).map((group) => group.changes), [
            [{ op: 'newParticipant', id: 'id1', name: 'Robin Example', country: null, externalId: null }],
            [{ op: 'newParticipant', id: 'id2', name: 'Sam Placeholder', country: null, externalId: null }],
        ]);
        assert.deepEqual(action.label, { key: 'paste' });
        assert.deepEqual([...lineOfGroup.values()], ['l0', 'l1']);
        assert.deepEqual(peopleIds, ['id1', 'id2']);
        // One undo step: every new person removed again
        assert.deepEqual(wireGroups(action.inverse).flatMap((group) => group.changes), [
            { op: 'remove', participant: 'id2' },
            { op: 'remove', participant: 'id1' },
        ]);
    });

    test('name ⇥ country: a code or a name in the page language; an unknown country is left out and said', () => {
        const m = model();
        const p = plan(m, 'Robin Example\tCZ\nSam Placeholder\tUnited States\nDana Mock\tAtlantis\nEve Blank\t\n');
        assert.deepEqual(p.lines.map((line) => [line.name, line.country, line.countryText, line.status]), [
            ['Robin Example', 'cz', null, LINE_NEW],
            ['Sam Placeholder', 'us', null, LINE_NEW],
            ['Dana Mock', null, 'Atlantis', LINE_NEW],
            ['Eve Blank', null, null, LINE_NEW],
        ]);
    });

    test('name ⇥ country ⇥ external id', () => {
        const m = model();
        const p = plan(m, 'Robin Example\tca\tWJPF-77\n');
        const { action } = namePasteAction(m, p, {}, { newId: ids(), countries: new Set(Object.keys(COUNTRIES)) });
        assert.deepEqual(wireGroups(action.groups)[0].changes, [{ op: 'newParticipant', id: 'id1', name: 'Robin Example', country: 'ca', externalId: 'WJPF-77' }]);
    });

    test('a name already on the list is left out (case, accents, white space and dashes do not matter)', () => {
        const m = model();
        const p = plan(m, 'kim  EXAMPLE\nPát Sample\nJo–Do\nNew Person\n');
        assert.deepEqual(statuses(p), [['kim EXAMPLE', LINE_EXISTING], ['Pát Sample', LINE_EXISTING], ['Jo–Do', LINE_NEW], ['New Person', LINE_NEW]]);
        assert.deepEqual(p.lines[0].matches, ['p-kim']);
        assert.equal(p.counts.existing, 2);

        const { action } = namePasteAction(m, p, {}, { newId: ids() });
        assert.deepEqual(action.groups.map((group) => group.changes[0].name), ['Jo–Do', 'New Person']);
    });

    test('a removed person is offered to restore, unticked - ticked it is a restore, not a new person', () => {
        const m = model();
        const p = plan(m, 'Ola Fictive\n');
        assert.deepEqual(statuses(p), [['Ola Fictive', LINE_REMOVED]]);
        assert.deepEqual(p.lines[0].matches, ['p-ola']);
        assert.equal(p.lines[0].tick, false);
        assert.equal(namePasteAction(m, p, {}, { newId: ids() }).action.groups.length, 0);

        const { action } = namePasteAction(m, p, { l0: true }, { newId: ids() });
        assert.deepEqual(wireGroups(action.groups).map((group) => group.changes), [[{ op: 'restore', participant: 'p-ola' }]]);
        assert.deepEqual(wireGroups(action.inverse).map((group) => group.changes), [[{ op: 'remove', participant: 'p-ola' }]]);
    });

    test('a name twice in the paste is added once and listed', () => {
        const m = model();
        const p = plan(m, 'Robin Example\nrobin example\nRobin Example\tcz\n');
        assert.deepEqual(statuses(p), [['Robin Example', LINE_NEW], ['robin example', LINE_DUPLICATE], ['Robin Example', LINE_DUPLICATE]]);
        assert.equal(p.lines[1].sameAs, 0);
        assert.equal(p.counts.duplicate, 2);
        assert.equal(namePasteAction(m, p, {}, { newId: ids() }).action.groups.length, 1);
    });

    test('lines the server would refuse are not possible: too long, an external id too long, no name', () => {
        const m = model();
        const p = plan(m, `${'x'.repeat(256)}\nShort\t\t${'y'.repeat(256)}\n\tcz\n`);
        assert.deepEqual(p.lines.map((line) => [line.status, line.reason]), [
            [LINE_INVALID, 'name_too_long'],
            [LINE_INVALID, 'external_id_too_long'],
            [LINE_INVALID, 'name_blank'],
        ]);
        assert.equal(namePasteAction(m, p, { l0: true, l1: true, l2: true }, { newId: ids() }).action.groups.length, 0);
    });

    test('a header line is left out - only as the first line', () => {
        const m = model();
        const p = plan(m, 'Name\tCountry\nRobin Example\tcz\nname\n');
        assert.deepEqual(statuses(p), [['Name', LINE_HEADER], ['Robin Example', LINE_NEW], ['name', LINE_NEW]]);
        assert.equal(p.counts.header, 1);
    });

    test('empty lines are no people; names are cleaned like the server cleans them', () => {
        const m = model();
        const p = plan(m, '  Robin    Example \r\n\r\n\t\t\r\nSam Placeholder\r\n');
        assert.deepEqual(statuses(p), [['Robin Example', LINE_NEW], ['Sam Placeholder', LINE_NEW]]);
        assert.deepEqual(p.lines.map((line) => line.index), [0, 3]);
    });

    test('unticking a new name leaves it out; the ticks of the preview decide', () => {
        const m = model();
        const p = plan(m, 'A One\nB Two\nC Three\n');
        const { action } = namePasteAction(m, p, { l1: false }, { newId: ids() });
        assert.deepEqual(action.groups.map((group) => group.changes[0].name), ['A One', 'C Three']);
    });

    test('a refusal made in the browser points at its line', () => {
        const m = model();
        const p = plan(m, 'Robin Example\tcz\n');
        // The page's country list does not know "cz" (an outdated list): refused in the browser, never sent
        const { action } = namePasteAction(m, p, {}, { newId: ids(), countries: new Set(['us']) });
        assert.equal(action.groups.length, 0);
        assert.equal(action.errors[0].reason, 'invalid_country');
        assert.equal(lineOfError(action.errors[0], p), 'l0');
    });

    test('fifteen names from Excel through the plan: counts per status', () => {
        const m = model();
        const names = Array.from({ length: 15 }, (_, index) => `Person ${String.fromCharCode(65 + index)} Example`);
        names[3] = 'Kim Example';
        names[7] = 'Ola Fictive';
        names[11] = 'person a example';
        const p = plan(m, `${names.join('\r\n')}\r\n`);
        assert.deepEqual(p.counts, { new: 12, existing: 1, removed: 1, duplicate: 1, invalid: 0, header: 0 });
    });
}
