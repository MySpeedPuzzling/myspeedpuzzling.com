// Adding people by pasting names (assets/participants_sheet/people_paste.js): `name`, `name ⇥ country`,
// `name ⇥ country ⇥ external id` per line, matched by the name key against the active and the removed people.
import assert from 'node:assert/strict';
import { SheetModel } from '../../assets/participants_sheet/sheet_model.js';
import { wireGroups } from '../../assets/participants_sheet/sheet_changes.js';
import { parseClipboardText } from '../../assets/participants_sheet/tsv.js';
import {
    HINT_CLOSE,
    HINT_COUNTRY,
    HINT_EMAIL,
    HINT_NUMBER,
    INTO_ALREADY,
    INTO_AMBIGUOUS,
    INTO_PUT,
    LINE_DUPLICATE,
    LINE_EXISTING,
    LINE_HEADER,
    LINE_INVALID,
    LINE_NEW,
    LINE_REMOVED,
    boundedDistance,
    closeNames,
    lineOfError,
    looksLikeNoName,
    namePasteAction,
    placementOf,
    planNamePaste,
} from '../../assets/participants_sheet/people_paste.js';
import { ids, person, place, smallState } from '../participants-sheet-core/fixture.mjs';

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
        const p = plan(m, 'A One\nB Two\nC Quinn\n');
        const { action } = namePasteAction(m, p, { l1: false }, { newId: ids() });
        assert.deepEqual(action.groups.map((group) => group.changes[0].name), ['A One', 'C Quinn']);
    });

    test('BR9: a new name close to somebody on the list is offered unticked - "Did you mean …?" (1-2 edits, keys of 6+)', () => {
        const m = model();
        // Kim Example is on the list, Ola Fictive was removed - both count; Jo Do is too short to compare
        const p = plan(m, 'Kim Exampel\nOla Fictiv\nJo Da\nKim Westwood\nRobin Sampler\n');
        assert.deepEqual(p.lines.map((line) => [line.name, line.status, line.hint?.kind ?? null, line.hint?.ids ?? null, line.tick]), [
            ['Kim Exampel', LINE_NEW, HINT_CLOSE, ['p-kim'], false],
            ['Ola Fictiv', LINE_NEW, HINT_CLOSE, ['p-ola'], false],
            ['Jo Da', LINE_NEW, null, null, true],
            ['Kim Westwood', LINE_NEW, null, null, true],
            ['Robin Sampler', LINE_NEW, null, null, true],
        ]);
        // Not ticked = not added, unless the organiser ticks it
        assert.deepEqual(namePasteAction(m, p, {}, { newId: ids() }).action.groups.map((group) => group.changes[0].name), ['Jo Da', 'Kim Westwood', 'Robin Sampler']);
        assert.deepEqual(namePasteAction(m, p, { l0: true }, { newId: ids() }).action.groups.map((group) => group.changes[0].name), ['Kim Exampel', 'Jo Da', 'Kim Westwood', 'Robin Sampler']);
    });

    test('BR9: a value that is a country code, a number or an e-mail address is offered unticked', () => {
        const m = model();
        const p = plan(m, 'CZ\tRobin Example\n12:34\nrobin@example.com\n#12\nZed Example\nAtl\n');
        assert.deepEqual(p.lines.map((line) => [line.name, line.hint?.kind ?? null, line.tick]), [
            ['CZ', HINT_COUNTRY, false],
            ['12:34', HINT_NUMBER, false],
            ['robin@example.com', HINT_EMAIL, false],
            ['#12', HINT_NUMBER, false],
            ['Zed Example', null, true],
            // Three letters nobody knows as a country - a name
            ['Atl', null, true],
        ]);
    });

    test('the close-name rule: bounded edit distance, no comparison below 6 characters', () => {
        assert.equal(boundedDistance('kimexample', 'kimexampel'), 2);
        assert.equal(boundedDistance('kimexample', 'kimexample'), 0);
        assert.equal(boundedDistance('abcdefgh', 'xyzdefgh'), 3, 'more than 2 = 3');
        assert.equal(boundedDistance('abc', 'abcdef'), 3, 'lengths too far apart');
        assert.equal(boundedDistance('žluťak', 'zlutak'), 2, 'code points, not bytes');
        assert.deepEqual(closeNames('jodax', [{ key: 'jodox', length: 5, ids: ['x'] }]), [], 'short keys are never close');
        assert.deepEqual(closeNames('robinexample', [{ key: 'robinexampel', length: 12, ids: ['a'] }, { key: 'robinexample', length: 12, ids: ['same'] }]), ['a']);
        assert.equal(looksLikeNoName('Bo', () => 'bo'), HINT_COUNTRY);
        assert.equal(looksLikeNoName('Bo', () => undefined), null);
        assert.equal(looksLikeNoName('Bo Li', () => 'bo'), null);
    });

    test('BR3: "and put them into" a solo round - new people placed in their own group, people on the list put in too, one undo step', () => {
        const state = smallState();
        // Lee is in Solo already, a second "Max Demo" makes that name ambiguous
        state.places.push(place('e-lee-solo', 'p-lee', 'r-solo'));
        state.people.push(person('p-max2', 'Max Demo'));
        const m = new SheetModel(state, { now: () => 0 });
        const p = plan(m, 'Robin Example\tcz\nKim Example\nAna Example\nLee Mock\nMax Demo\nOla Fictive\n');
        assert.deepEqual(p.lines.map((line) => placementOf(m, line, 'r-solo')), [INTO_PUT, INTO_ALREADY, INTO_PUT, INTO_ALREADY, INTO_AMBIGUOUS, INTO_PUT]);
        assert.equal(placementOf(m, p.lines[0], null), null);

        const { action, placed, peopleIds, lineOfPerson } = namePasteAction(m, p, { l5: true }, { newId: ids(), countries: new Set(Object.keys(COUNTRIES)), roundId: 'r-solo' });
        assert.deepEqual(wireGroups(action.groups).map((group) => group.changes), [
            [{ op: 'newParticipant', id: 'id1', name: 'Robin Example', country: 'cz', externalId: null }, { op: 'place', participant: 'id1', round: 'r-solo', from: 'out', to: 'in' }],
            [{ op: 'place', participant: 'p-ana', round: 'r-solo', from: 'out', to: 'in' }],
            [{ op: 'restore', participant: 'p-ola' }, { op: 'place', participant: 'p-ola', round: 'r-solo', from: 'out', to: 'in' }],
        ]);
        assert.deepEqual(placed, ['id1', 'p-ana', 'p-ola']);
        assert.deepEqual(peopleIds, ['id1', 'p-ola']);
        assert.equal(lineOfPerson.get('p-ana'), 'l2');
        // The undo takes everything back: Ola removed again, Ana out, Robin removed
        assert.deepEqual(wireGroups(action.inverse).map((group) => group.changes.map((change) => [change.op, change.participant, change.to ?? null])), [
            [['place', 'p-ola', 'out'], ['remove', 'p-ola', null]],
            [['place', 'p-ana', 'out']],
            [['place', 'id1', 'out'], ['remove', 'id1', null]],
        ]);

        // Without a round: only the new person (the ticks decide), nobody on the list moves
        assert.deepEqual(namePasteAction(m, p, {}, { newId: ids() }).action.groups.map((group) => group.changes.map((change) => change.op)), [['newParticipant']]);
        // A line refused by the dry run is skipped whatever its tick
        assert.equal(namePasteAction(m, p, {}, { newId: ids(), roundId: 'r-solo', skip: new Set(['l2']) }).placed.includes('p-ana'), false);
    });

    test('a refusal of a placement points at its line (people of the paste by id)', () => {
        const m = model();
        const p = plan(m, 'Ana Example\n');
        const { lineOfPerson } = namePasteAction(m, p, {}, { newId: ids(), roundId: 'r-solo' });
        assert.equal(lineOfError({ reason: 'participant_removed', change: { op: 'place', participant: 'p-ana', round: 'r-solo' } }, p, lineOfPerson), 'l0');
        assert.equal(lineOfError({ reason: 'participant_removed', change: { op: 'place', participant: 'p-ana', round: 'r-solo' } }, p), 'l0');
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
