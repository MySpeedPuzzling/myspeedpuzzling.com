// Link suggestions in the participants sheet (assets/participants_sheet/link_suggestions.js, participant-linking.md
// §7.2, R2, R7): what is still true of an answer, the one pre-tick rule, the groups sent (a merge = remove + link in one
// atomic group, one group per person), the undo, the People filter and the dialog (jsdom).
import assert from 'node:assert/strict';
import { setupDom } from '../participants-sheet-core/dom.mjs';
import { person, smallState } from '../participants-sheet-core/fixture.mjs';
import { SheetModel } from '../../assets/participants_sheet/sheet_model.js';
import { wireGroups } from '../../assets/participants_sheet/sheet_changes.js';
import { FILTERS, filterCounts, matchesFilter, offeredFor } from '../../assets/participants_sheet/views/people_view.js';
import {
    JOINED_THEMSELVES,
    LINKED_BEFORE,
    NAME_COUNTRY,
    canMerge,
    currentSuggestions,
    initialSelection,
    isSuggested,
    openSuggestionsDialog,
    pretickedPlayer,
    rememberSuggested,
    requestSuggestions,
    suggestionAction,
} from '../../assets/participants_sheet/link_suggestions.js';

const profile = (id, extra = {}) => ({ id, visible: true, name: `Name ${id}`, code: id.replace('pl-', ''), country: 'cz', avatar: null, profileUrl: `/en/player/${id}`, ...extra });

function candidate(tier, playerId, extra = {}) {
    return { tier, player: profile(playerId), countryMatches: false, follows: false, nameFromOrganiser: tier === LINKED_BEFORE ? true : null, joinedAt: null, linkedAt: null, selfJoined: null, ...extra };
}

// Ana (not linked, cz) and "Jo Do" the organiser imported; "jo do" tapped "I'm going" himself (self-joined, pl-jo)
function model() {
    const state = smallState();
    state.people = state.people.map((row) => (row.id === 'p-ana' ? { ...row, country: 'cz' } : row));
    state.people.push(person('p-jo-self', 'jo do', { source: 'self_joined', connectedAt: '2026-10-01T10:00:00+00:00', player: profile('pl-jo') }));

    return new SheetModel(state, { now: () => 0 });
}

function ids(prefix = 'g') {
    let counter = 0;

    return () => `${prefix}${++counter}`;
}

const merge = (extra = {}) => candidate(JOINED_THEMSELVES, 'pl-jo', { joinedAt: '2026-10-01T10:00:00+00:00', selfJoined: { participant: 'p-jo-self', mergeRefusal: null }, ...extra });

const LABELS = {
    title: 'title', help: 'help', empty: 'empty', submit: 'Link (%count%)', cancel: 'cancel', several: 'several', notNow: 'not now',
    follows: 'follows', joinedAt: 'joined %date%', linkedAt: 'linked at %event%', hiddenProfile: 'hidden', mergeByHand: 'Merge by hand',
    tier: { [JOINED_THEMSELVES]: 'T1', [LINKED_BEFORE]: 'T2', [NAME_COUNTRY]: 'T3' },
    refusal: { paid: 'paid' },
    country: (code) => code.toUpperCase(),
    date: (value) => value.slice(0, 10),
};

export default function (test) {
    test('R2: only "linked at another date" under the organiser’s name, the only candidate, with the same country is pre-ticked', () => {
        const linked = candidate(LINKED_BEFORE, 'pl-1', { countryMatches: true });
        assert.equal(pretickedPlayer({ participant: 'p-ana', candidates: [linked] }), 'pl-1');
        assert.equal(pretickedPlayer({ participant: 'p-ana', candidates: [{ ...linked, countryMatches: false }] }), null);
        // The earlier row was a self-join: its name is the player's free-text profile name - a renamed cheater (R2)
        assert.equal(pretickedPlayer({ participant: 'p-ana', candidates: [{ ...linked, nameFromOrganiser: false }] }), null);
        // Name-based tiers never - a self-joined row's name is free text too
        assert.equal(pretickedPlayer({ participant: 'p-ana', candidates: [candidate(NAME_COUNTRY, 'pl-1', { countryMatches: true })] }), null);
        assert.equal(pretickedPlayer({ participant: 'p-ana', candidates: [merge({ countryMatches: true })] }), null);
        // Several candidates: none chosen
        assert.equal(pretickedPlayer({ participant: 'p-ana', candidates: [linked, candidate(LINKED_BEFORE, 'pl-2', { countryMatches: true })] }), null);
        assert.deepEqual([...initialSelection([{ participant: 'p-ana', candidates: [linked] }, { participant: 'p-jo', candidates: [merge()] }])], [['p-ana', 'pl-1']]);
    });

    test('what is still true: people linked or removed meanwhile drop out, so do candidates linked to somebody else', () => {
        const m = model();
        const answer = [
            { participant: 'p-ana', name: 'Ana Example', country: 'cz', candidates: [candidate(NAME_COUNTRY, 'pl-1'), candidate(NAME_COUNTRY, 'pl-kim')] },
            { participant: 'p-jo', name: 'Jo Do', country: null, candidates: [merge()] },
            { participant: 'p-kim', name: 'Kim Example', country: 'us', candidates: [candidate(NAME_COUNTRY, 'pl-9')] },
            { participant: 'p-ola', name: 'Ola Fictive', country: null, candidates: [candidate(NAME_COUNTRY, 'pl-8')] },
            { participant: 'p-gone', name: 'Gone', country: null, candidates: [candidate(NAME_COUNTRY, 'pl-7')] },
        ];
        const now = currentSuggestions(answer, m);

        // Kim is linked already, Ola removed, p-gone unknown; Kim's profile pl-kim is nobody else's to suggest
        assert.deepEqual(now.map((entry) => entry.participant), ['p-ana', 'p-jo']);
        assert.deepEqual(now[0].candidates.map((item) => item.player.id), ['pl-1']);
        // A merge needs the self-joined row on the list, still linked to that player
        const withoutSelf = currentSuggestions([{ participant: 'p-jo', candidates: [merge({ selfJoined: { participant: 'p-ola', mergeRefusal: null } })] }], m);
        assert.deepEqual(withoutSelf, []);
    });

    test('Link: one group per ticked person, a merge = remove the self-joined row then link, in one group', () => {
        const m = model();
        const entries = [
            { participant: 'p-ana', candidates: [candidate(LINKED_BEFORE, 'pl-1', { countryMatches: true })] },
            { participant: 'p-jo', candidates: [merge()] },
            { participant: 'p-lee', candidates: [candidate(NAME_COUNTRY, 'pl-2')] },
        ];
        const action = suggestionAction(m, entries, new Map([['p-ana', 'pl-1'], ['p-jo', 'pl-jo']]), { newId: ids() });

        assert.deepEqual(action.label, { key: 'link_suggestions', count: 2 });
        assert.deepEqual(wireGroups(action.groups), [
            { id: 'g1', changes: [{ op: 'player', participant: 'p-ana', from: null, to: 'pl-1' }] },
            { id: 'g2', changes: [{ op: 'remove', participant: 'p-jo-self' }, { op: 'player', participant: 'p-jo', from: null, to: 'pl-jo' }] },
        ]);
        assert.deepEqual(action.errors, []);
        // The undo of the merge: unlink first, then the self-joined row back
        const undoMerge = action.inverse.find((group) => group.inverseOf === 'g2');
        assert.deepEqual(undoMerge.changes.map((change) => change.op), ['player', 'restore']);
    });

    test('R7: a merge the self-joined row refuses is "Merge by hand" - never sent', () => {
        const m = model();
        const refused = merge({ selfJoined: { participant: 'p-jo-self', mergeRefusal: 'paid' } });
        assert.equal(canMerge(refused), false);
        assert.equal(canMerge(merge()), true);

        const action = suggestionAction(m, [{ participant: 'p-jo', candidates: [refused] }], new Map([['p-jo', 'pl-jo']]), { newId: ids() });
        assert.deepEqual(action.groups, []);
    });

    test('a refused person never stops the others - the refusal is reported', () => {
        const m = model();
        // pl-kim is linked to Kim already: the browser refuses that group, Ana's goes out
        const entries = [
            { participant: 'p-lee', candidates: [candidate(NAME_COUNTRY, 'pl-kim')] },
            { participant: 'p-ana', candidates: [candidate(NAME_COUNTRY, 'pl-1')] },
        ];
        const action = suggestionAction(m, entries, new Map([['p-lee', 'pl-kim'], ['p-ana', 'pl-1']]), { newId: ids() });

        assert.deepEqual(action.groups.map((group) => group.changes[0].participant), ['p-ana']);
        assert.deepEqual(action.errors.map((error) => error.reason), ['player_linked_elsewhere']);
    });

    test('the People filter: offered with the endpoint only, shows the remembered people while not linked', () => {
        const m = model();
        assert.ok(!offeredFor(FILTERS, m.competition).some((item) => item.key === 'suggestions'));
        const filters = offeredFor(FILTERS, m.competition, [], { suggestions: true });
        assert.ok(filters.some((item) => item.key === 'suggestions'));

        assert.equal(filterCounts(m, filters).suggestions, 0);
        rememberSuggested(m, [{ participant: 'p-ana' }, { participant: 'p-kim' }]);
        assert.equal(isSuggested(m, 'p-ana'), true);
        // Kim is linked - a suggestion no more
        assert.equal(matchesFilter(m, m.person('p-kim'), 'suggestions'), false);
        assert.equal(matchesFilter(m, m.person('p-ana'), 'suggestions'), true);
        assert.equal(filterCounts(m, filters).suggestions, 1);
        // Another model knows nothing of it
        assert.equal(isSuggested(model(), 'p-ana'), false);
    });

    test('the endpoint: its people, anything else as its kind', async () => {
        const calls = [];
        const request = async (url, options) => {
            calls.push({ url, ...options });

            return { kind: 'ok', status: 200, data: { people: [{ participant: 'p-ana', candidates: [] }] } };
        };

        assert.deepEqual(await requestSuggestions({ url: '/s', request }), { ok: true, people: [{ participant: 'p-ana', candidates: [] }] });
        assert.deepEqual(calls, [{ url: '/s', method: 'GET' }]);
        assert.deepEqual(await requestSuggestions({ url: '/s', request: async () => ({ kind: 'ok', data: {} }) }), { ok: false, reason: 'server' });
        assert.deepEqual(await requestSuggestions({ url: '/s', request: async () => ({ kind: 'offline' }) }), { ok: false, reason: 'offline' });
    });

    test('the dialog: the pre-ticked one ticked, several = radios with none chosen, a refused merge disabled; Link sends the ticked', () => {
        setupDom();
        const sent = [];
        const entries = [
            { participant: 'p-ana', name: 'Ana <Example>', country: 'cz', candidates: [candidate(LINKED_BEFORE, 'pl-1', { countryMatches: true, linkedAt: { name: 'Open 2025', date: null } })] },
            { participant: 'p-lee', name: 'Lee Mock', country: null, candidates: [candidate(NAME_COUNTRY, 'pl-2'), candidate(NAME_COUNTRY, 'pl-3', { follows: true })] },
            { participant: 'p-jo', name: 'Jo Do', country: null, candidates: [merge({ selfJoined: { participant: 'p-jo-self', mergeRefusal: 'paid' } })] },
        ];
        const dialog = openSuggestionsDialog(document.body, { entries, labels: LABELS, onSubmit: (selection) => sent.push([...selection]) });

        assert.ok(!dialog.innerHTML.includes('<Example>'), 'names are escaped');
        const ana = dialog.querySelector('[data-suggestion="p-ana"] [data-suggestion-choice]');
        assert.equal(ana.type, 'checkbox');
        assert.equal(ana.checked, true);
        assert.match(dialog.querySelector('[data-suggestion="p-ana"]').textContent, /linked at Open 2025/);

        const radios = [...dialog.querySelectorAll('[data-suggestion="p-lee"] [data-suggestion-choice]')];
        assert.deepEqual(radios.map((radio) => [radio.type, radio.checked]), [['radio', false], ['radio', false]]);
        assert.equal(dialog.querySelector('[data-suggestion="p-lee"] [data-suggestion-none]').checked, true);

        const jo = dialog.querySelector('[data-suggestion="p-jo"] [data-suggestion-choice]');
        assert.equal(jo.disabled, true);
        assert.match(dialog.querySelector('[data-suggestion="p-jo"]').textContent, /Merge by hand: paid/);

        const submit = dialog.querySelector('[data-suggestions-submit]');
        assert.equal(submit.textContent, 'Link (1)');

        radios[1].checked = true;
        radios[1].dispatchEvent(new Event('change', { bubbles: true }));
        assert.equal(submit.textContent, 'Link (2)');

        ana.checked = false;
        ana.dispatchEvent(new Event('change', { bubbles: true }));
        assert.equal(submit.textContent, 'Link (1)');

        submit.click();
        assert.deepEqual(sent, [[['p-lee', 'pl-3']]]);
        assert.equal(document.querySelector('[data-link-suggestions-dialog]'), null, 'closed and gone');
    });

    test('the dialog without suggestions says so and Link is disabled', () => {
        setupDom();
        const dialog = openSuggestionsDialog(document.body, { entries: [], labels: LABELS });

        assert.match(dialog.textContent, /empty/);
        assert.equal(dialog.querySelector('[data-suggestions-submit]').disabled, true);
        dialog.querySelector('[data-suggestions-cancel]').click();
        assert.equal(document.querySelector('[data-link-suggestions-dialog]'), null);
    });
}
