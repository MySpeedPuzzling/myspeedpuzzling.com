// Runs the pure parts of the moderators' console and the host's studio page (docs/features/online-competitions/
// contracts/phase-1b.md stream VM) under node: the grid's order, paging and which cameras are received at which size
// (assets/online/console_grid.js), push-to-talk (console_talk.js), the studio's invitations (console_invites.js) and
// the studio page's audience, stage and lists (console_studio.js). Prints {scenario: "ok" | error} as JSON;
// tests/OnlineConsoleScriptTest.php asserts every one is "ok".

import assert from 'node:assert/strict';
import {
    dimensionsFor,
    LAYER_HIGH,
    LAYER_LOW,
    orderTiles,
    PAGE_SIZE,
    pageOf,
    pageWith,
    SPOTLIGHT_DIMENSIONS,
    stepSpotlight,
    subscriptionPlan,
    TILE_DIMENSIONS,
    tileVideoState,
} from '../assets/online/console_grid.js';
import { formatOnAir, INITIAL_TALK, isOnAir, spaceTalks, talkReducer } from '../assets/online/console_talk.js';
import {
    formatRemaining,
    hasOpenInvite,
    IDLE_POLL_MS,
    inviteStatusAt,
    inviteView,
    mergeInvite,
    mergeInvites,
    OPEN_POLL_MS,
    studioPollDelay,
} from '../assets/online/console_invites.js';
import { audienceOf, inviteOfGuest, stageOf, studioSections } from '../assets/online/console_studio.js';

const NOW = 1_800_000_000_000;

function item(id, finishMs = null) {
    return {
        participant: { participantId: id, name: id, finish: finishMs === null ? null : { momentMs: finishMs } },
        view: { finished: finishMs !== null },
    };
}

function invite(overrides = {}) {
    return {
        id: '018d0060-0000-7000-8000-000000000001',
        participantId: 'p-olivia',
        status: 'invited',
        invitedAtMs: NOW - 10_000,
        expiresAtMs: NOW + 110_000,
        onAirFromMs: null,
        onAirToMs: null,
        ...overrides,
    };
}

function run(state, ...events) {
    return events.reduce((current, event) => talkReducer(current, event), state);
}

const scenarios = {
    async 'grid order: the finished first, the newest tap on top, then everybody else as given (by name)'() {
        const items = [item('anna'), item('bert', NOW - 60_000), item('cecil'), item('dora', NOW - 5_000), item('emil', NOW - 60_000), item('fay')];

        assert.deepEqual(orderTiles(items).map((entry) => entry.participant.participantId), ['dora', 'bert', 'emil', 'anna', 'cecil', 'fay']);
        // A finish without a known moment comes after the known ones; nothing finished: as given
        const unknown = { participant: { participantId: 'gus', finish: null }, view: { finished: true } };
        assert.deepEqual(orderTiles([item('anna'), unknown, item('bert', NOW)]).map((entry) => entry.participant.participantId), ['bert', 'gus', 'anna']);
        assert.deepEqual(orderTiles([item('b'), item('a')]).map((entry) => entry.participant.participantId), ['b', 'a']);
        assert.deepEqual(orderTiles(null), []);
        // A new array - the given one stays
        assert.notEqual(orderTiles(items), items);
        assert.equal(items[0].participant.participantId, 'anna');
    },

    async 'grid order, detection on: raised hands detected count as a finish - by the view\'s finish moment'() {
        // review_control.js participantView(): the finish the tile shows (a tap or, detection on, a candidate)
        const detected = { participant: { participantId: 'hana', finish: null, candidate: { momentMs: NOW - 1_000 } }, view: { finished: true, finishMomentMs: NOW - 1_000 } };
        const later = { participant: { participantId: 'ivan', finish: { momentMs: NOW - 90_000 } }, view: { finished: true, finishMomentMs: NOW - 2_000 } };
        const items = [item('anna'), item('bert', NOW - 60_000), detected, later];

        assert.deepEqual(orderTiles(items).map((entry) => entry.participant.participantId), ['hana', 'ivan', 'bert', 'anna']);
    },

    async 'paging: 25 tiles a page, a page out of range is the nearest one, 1-based range for the pager'() {
        const ids = Array.from({ length: 63 }, (_, i) => `p${i}`);
        assert.equal(PAGE_SIZE, 25);

        const first = pageOf(ids);
        assert.deepEqual([first.items.length, first.page, first.pages, first.from, first.to, first.total], [25, 0, 3, 1, 25, 63]);
        const last = pageOf(ids, 2);
        assert.deepEqual([last.items.length, last.page, last.from, last.to], [13, 2, 51, 63]);
        assert.equal(pageOf(ids, 7).page, 2, 'the grid shrank - the last page');
        assert.equal(pageOf(ids, -3).page, 0);
        assert.equal(pageOf(ids, 1.5).page, 0, 'not a page number');

        const empty = pageOf([], 4);
        assert.deepEqual([empty.items, empty.page, empty.pages, empty.from, empty.to], [[], 0, 1, 0, 0]);
        assert.equal(pageOf(ids, 0, 10).pages, 7);

        assert.equal(pageWith(ids, 'p0'), 0);
        assert.equal(pageWith(ids, 'p25'), 1);
        assert.equal(pageWith(ids, 'p62'), 2);
        assert.equal(pageWith(ids, 'nobody'), null);
    },

    async 'layers: the page small, the spotlight large wherever its tile is, nobody else received'() {
        assert.deepEqual([...subscriptionPlan(['a', 'b', 'c'], null)], [['a', LAYER_LOW], ['b', LAYER_LOW], ['c', LAYER_LOW]]);
        assert.deepEqual([...subscriptionPlan(['a', 'b'], 'b')], [['a', LAYER_LOW], ['b', LAYER_HIGH]]);
        // The spotlight of a camera on another page: received large, the page's ones small
        assert.deepEqual([...subscriptionPlan(['a'], 'z')], [['a', LAYER_LOW], ['z', LAYER_HIGH]]);
        assert.deepEqual([...subscriptionPlan([], null)], []);
        assert.deepEqual([...subscriptionPlan([null, '', 'a'], '')], [['a', LAYER_LOW]]);

        assert.deepEqual(dimensionsFor(LAYER_LOW), { width: 320, height: 180 });
        assert.deepEqual(dimensionsFor(LAYER_HIGH), { width: 1280, height: 720 });
        assert.deepEqual(TILE_DIMENSIONS, { width: 320, height: 180 });
        assert.deepEqual(SPOTLIGHT_DIMENSIONS, { width: 1280, height: 720 });
        // A copy - the constants stay
        dimensionsFor(LAYER_LOW).width = 1;
        assert.equal(TILE_DIMENSIONS.width, 320);
    },

    async 'a tile without video says why'() {
        const camera = { isMuted: false };

        assert.equal(tileVideoState({ connected: false, entry: { camera }, playing: true }), 'connecting');
        assert.equal(tileVideoState({ connected: true, entry: null }), 'not_joined');
        assert.equal(tileVideoState({ connected: true, entry: { camera: null } }), 'no_camera');
        assert.equal(tileVideoState({ connected: true, entry: { camera: { isMuted: true } }, playing: true }), 'paused');
        assert.equal(tileVideoState({ connected: true, entry: { camera }, playing: false }), 'loading');
        assert.equal(tileVideoState({ connected: true, entry: { camera }, playing: true }), 'playing');
        assert.equal(tileVideoState(), 'connecting');

        // Not in the hall because the phone is in the studio - and back in the hall, the hall's camera counts
        assert.equal(tileVideoState({ connected: true, entry: null, inStudio: true }), 'in_studio');
        assert.equal(tileVideoState({ connected: true, entry: { camera }, playing: true, inStudio: true }), 'playing');
        assert.equal(tileVideoState({ connected: false, entry: null, inStudio: true }), 'connecting');
    },

    async 'spotlight: the camera before and after in the console\'s order, the same one at either end'() {
        const ids = ['dora', 'bert', 'anna'];

        assert.equal(stepSpotlight(ids, 'bert', 1), 'anna');
        assert.equal(stepSpotlight(ids, 'bert', -1), 'dora');
        assert.equal(stepSpotlight(ids, 'anna', 1), 'anna');
        assert.equal(stepSpotlight(ids, 'dora', -1), 'dora');
        assert.equal(stepSpotlight(ids, 'gone', 1), null);
    },

    async 'push-to-talk: the microphone off until asked for, on air only while held'() {
        assert.deepEqual(INITIAL_TALK, { mic: 'off', holders: [], latched: false, error: null });
        assert.equal(isOnAir(INITIAL_TALK), false);

        // Nothing holds before the microphone is ready
        assert.equal(talkReducer(INITIAL_TALK, { type: 'press', source: 'space' }), INITIAL_TALK);
        assert.equal(talkReducer(INITIAL_TALK, { type: 'latch' }), INITIAL_TALK);

        const starting = talkReducer(INITIAL_TALK, { type: 'enable' });
        assert.equal(starting.mic, 'starting');
        assert.equal(talkReducer(starting, { type: 'press', source: 'space' }), starting, 'not yet');

        const ready = talkReducer(starting, { type: 'enabled' });
        assert.equal(ready.mic, 'ready');
        assert.equal(isOnAir(ready), false, 'published muted');

        const held = talkReducer(ready, { type: 'press', source: 'space' });
        assert.equal(isOnAir(held), true);
        assert.equal(talkReducer(held, { type: 'press', source: 'space' }), held, 'a repeated key-down');
        assert.equal(isOnAir(talkReducer(held, { type: 'release', source: 'space' })), false);
        assert.equal(talkReducer(ready, { type: 'release', source: 'space' }), ready, 'a key-up without its key-down');
    },

    async 'push-to-talk: two holders - the last release ends it; losing focus lets go of all'() {
        const ready = run(INITIAL_TALK, { type: 'enable' }, { type: 'enabled' });
        const both = run(ready, { type: 'press', source: 'space' }, { type: 'press', source: 'pointer' });

        assert.deepEqual(both.holders, ['space', 'pointer']);
        assert.equal(isOnAir(talkReducer(both, { type: 'release', source: 'space' })), true, 'the button still holds');
        assert.equal(isOnAir(run(both, { type: 'release', source: 'space' }, { type: 'release', source: 'pointer' })), false);
        assert.equal(isOnAir(talkReducer(both, { type: 'release_all' })), false, 'the window lost focus - no key-up comes');
        assert.equal(talkReducer(ready, { type: 'release_all' }), ready);
        assert.equal(talkReducer(ready, { type: 'press', source: '' }), ready);
    },

    async 'push-to-talk: the latch keeps talking until switched off - losing focus never ends it'() {
        const ready = run(INITIAL_TALK, { type: 'enable' }, { type: 'enabled' });
        const latched = talkReducer(ready, { type: 'latch' });

        assert.equal(isOnAir(latched), true);
        assert.equal(isOnAir(talkReducer(latched, { type: 'release_all' })), true);
        // Holding and letting go meanwhile: still on air
        assert.equal(isOnAir(run(latched, { type: 'press', source: 'pointer' }, { type: 'release', source: 'pointer' })), true);
        assert.equal(isOnAir(talkReducer(latched, { type: 'latch' })), false);
    },

    async 'push-to-talk: a microphone that fails, and the connection going'() {
        const failed = run(INITIAL_TALK, { type: 'enable' }, { type: 'failed', reason: 'mic_denied' });
        assert.deepEqual(failed, { mic: 'error', holders: [], latched: false, error: 'mic_denied' });
        assert.equal(run(INITIAL_TALK, { type: 'enable' }, { type: 'failed', reason: 'something odd' }).error, 'mic_failed');
        // Asked again
        assert.equal(talkReducer(failed, { type: 'enable' }).mic, 'starting');
        assert.equal(talkReducer(failed, { type: 'enable' }).error, null);

        const latched = run(INITIAL_TALK, { type: 'enable' }, { type: 'enabled' }, { type: 'latch' }, { type: 'press', source: 'space' });
        const off = talkReducer(latched, { type: 'off' });
        assert.deepEqual(off, { mic: 'off', holders: [], latched: false, error: null });
        assert.equal(talkReducer(INITIAL_TALK, { type: 'off' }), INITIAL_TALK);
        // Enabling twice does not start twice; unknown events change nothing
        const starting = talkReducer(INITIAL_TALK, { type: 'enable' });
        assert.equal(talkReducer(starting, { type: 'enable' }), starting);
        assert.equal(talkReducer(starting, { type: 'whatever' }), starting);
        assert.equal(talkReducer(INITIAL_TALK, { type: 'enabled' }), INITIAL_TALK, 'enabled without asking');
    },

    async 'push-to-talk: the Space key talks, unless it types into a field or has a modifier'() {
        const space = (target, extra = {}) => ({ code: 'Space', key: ' ', target, ...extra });

        assert.equal(spaceTalks(space({ tagName: 'BODY' })), true);
        assert.equal(spaceTalks(space({ tagName: 'BUTTON' })), true);
        assert.equal(spaceTalks(space({ tagName: 'DIV', isContentEditable: false })), true);
        assert.equal(spaceTalks(space({ tagName: 'input', type: 'radio' })), true, 'the grid\'s filters');
        assert.equal(spaceTalks(space({ tagName: 'INPUT', type: 'text' })), false);
        assert.equal(spaceTalks(space({ tagName: 'INPUT', type: 'datetime-local' })), false, '"Start at"');
        assert.equal(spaceTalks(space({ tagName: 'INPUT' })), false, 'an input without a type is a text input');
        assert.equal(spaceTalks(space({ tagName: 'TEXTAREA' })), false);
        assert.equal(spaceTalks(space({ tagName: 'SELECT' })), false);
        assert.equal(spaceTalks(space({ tagName: 'DIV', isContentEditable: true })), false);
        assert.equal(spaceTalks(space({ tagName: 'BODY' }, { ctrlKey: true })), false);
        assert.equal(spaceTalks(space({ tagName: 'BODY' }, { metaKey: true })), false);
        assert.equal(spaceTalks({ code: 'Enter', key: 'Enter', target: { tagName: 'BODY' } }), false);
        assert.equal(spaceTalks({ key: ' ', target: null }), true, 'an old browser without `code`');
        assert.equal(spaceTalks(), false);

        assert.equal(formatOnAir(7_400), '0:07');
        assert.equal(formatOnAir(65_000), '1:05');
        assert.equal(formatOnAir(-5), '0:00');
    },

    async 'invitations: invited past its expiry is expired, as of the server\'s time'() {
        assert.equal(inviteStatusAt(invite(), NOW), 'invited');
        assert.equal(inviteStatusAt(invite(), NOW + 110_000), 'expired');
        assert.equal(inviteStatusAt(invite({ status: 'joined' }), NOW + 999_000), 'joined', 'answered - never expires');
        assert.equal(inviteStatusAt(null, NOW), null);
        assert.equal(inviteStatusAt({ id: 'x', participantId: 'p', status: 'odd' }, NOW), null);
    },

    async 'invitations: one only moves forward, whichever answer arrives last'() {
        const invited = invite();
        const joined = invite({ status: 'joined', answeredAtMs: NOW - 5_000 });
        const onAir = invite({ status: 'joined', answeredAtMs: NOW - 5_000, onAirFromMs: NOW - 4_000 });
        const ended = invite({ status: 'ended', onAirToMs: NOW });

        assert.equal(mergeInvite(invited, joined).status, 'joined');
        assert.equal(mergeInvite(joined, invited).status, 'joined', 'an older poll never takes the answer back');
        assert.equal(mergeInvite(ended, onAir).status, 'ended', 'never back on air');

        // The moments add up from both
        const merged = mergeInvite(onAir, ended);
        assert.deepEqual([merged.status, merged.onAirFromMs, merged.onAirToMs, merged.answeredAtMs], ['ended', NOW - 4_000, NOW, NOW - 5_000]);
        assert.equal(mergeInvite(null, invited).status, 'invited');
        assert.equal(mergeInvite(invited, null), invited);
        assert.equal(mergeInvite(null, { nonsense: true }), null);
    },

    async 'invitations: a newer invitation of the participant replaces the older one'() {
        const old = invite({ status: 'declined' });
        const fresh = invite({ id: '018d0060-0000-7000-8000-000000000009', invitedAtMs: NOW, expiresAtMs: NOW + 120_000 });

        assert.equal(mergeInvite(old, fresh).id, fresh.id);
        assert.equal(mergeInvite(fresh, old).id, fresh.id, 'the old one arriving late changes nothing');

        // By participant, ids in lower case; a list or one
        let invites = mergeInvites(new Map(), [old, invite({ id: '018d0060-0000-7000-8000-000000000002', participantId: 'P-BERT' })]);
        assert.deepEqual([...invites.keys()], ['p-olivia', 'p-bert']);
        invites = mergeInvites(invites, fresh);
        assert.equal(invites.get('p-olivia').id, fresh.id);
        assert.equal(mergeInvites(invites, [null, 7]).size, 2);
        assert.notEqual(mergeInvites(invites, []), invites, 'a new map');
    },

    async 'invitations: what a tile offers - invite a finisher, withdraw a waiting one, thank a guest'() {
        assert.deepEqual(inviteView(null, NOW, true), { status: null, open: false, onAir: false, canInvite: true, canEnd: false, remainingMs: null });
        assert.equal(inviteView(null, NOW, false).canInvite, false, 'only a finisher');

        const waiting = inviteView(invite(), NOW, true);
        assert.deepEqual([waiting.status, waiting.open, waiting.canInvite, waiting.canEnd, waiting.remainingMs], ['invited', true, false, true, 110_000]);

        const joining = inviteView(invite({ status: 'joined' }), NOW, true);
        assert.deepEqual([joining.status, joining.onAir, joining.canEnd], ['joined', false, true]);
        const onAir = inviteView(invite({ status: 'joined', onAirFromMs: NOW - 1_000 }), NOW, true);
        assert.equal(onAir.onAir, true);

        assert.deepEqual([inviteView(invite({ status: 'declined' }), NOW, true).canInvite, inviteView(invite({ status: 'declined' }), NOW, true).canEnd], [true, false]);
        assert.equal(inviteView(invite(), NOW + 200_000, true).status, 'expired');
        assert.equal(inviteView(invite(), NOW + 200_000, true).canInvite, true);
        assert.equal(inviteView(invite({ status: 'ended', onAirFromMs: NOW - 9_000, onAirToMs: NOW }), NOW, true).status, 'ended');
        assert.equal(inviteView(invite({ status: 'ended' }), NOW, true).status, 'withdrawn', 'ended before anybody was on air');

        assert.equal(formatRemaining(110_000), '1:50');
        assert.equal(formatRemaining(1_200), '0:02');
        assert.equal(formatRemaining(0), '0:00');
    },

    async 'invitations: the studio is polled often only while something is happening'() {
        const quiet = mergeInvites(new Map(), [invite({ status: 'declined' }), invite({ id: 'i-2', participantId: 'p-2', status: 'ended' })]);
        assert.equal(hasOpenInvite(quiet, NOW), false);
        assert.equal(studioPollDelay(quiet, NOW), IDLE_POLL_MS);

        const waiting = mergeInvites(quiet, invite({ id: 'i-3', participantId: 'p-3' }));
        assert.equal(studioPollDelay(waiting, NOW), OPEN_POLL_MS);
        assert.equal(studioPollDelay(waiting, NOW + 200_000), IDLE_POLL_MS, 'it ran out');
        assert.equal(studioPollDelay(mergeInvites(quiet, invite({ id: 'i-4', participantId: 'p-4', status: 'joined' })), NOW + 999_000), OPEN_POLL_MS);
        assert.equal(hasOpenInvite(null, NOW), false);
        assert.equal(OPEN_POLL_MS < IDLE_POLL_MS, true);
    },

    async 'studio: the audience is LiveKit\'s count of the room less the visible ones and the host'() {
        assert.equal(audienceOf(4, 1), 2, 'the host, a co-host and two hidden spectators');
        assert.equal(audienceOf(1, 0), 0);
        assert.equal(audienceOf(0, 0), 0, 'no count from the server yet');
        assert.equal(audienceOf(2, 3), 0, 'the count lags behind a guest who just joined');
        assert.equal(audienceOf(undefined, 2), 0);
        assert.equal(audienceOf(7.9, null), 6);
    },

    async 'studio: guests, then co-hosts, then every screen shared - spectators never on the stage'() {
        const camera = { trackSid: 'TR_c' };
        const microphone = { trackSid: 'TR_m' };
        const screen = { trackSid: 'TR_s' };
        const stage = stageOf([
            { identity: 'm:host2', kind: 'moderator', id: 'host2', name: 'Co', camera, microphone, screen },
            { identity: 'p:olivia', kind: 'participant', id: 'olivia', name: 'Olivia', camera, microphone, screen: null },
            { identity: 'p:olivia:screen', kind: 'screen', id: 'olivia', name: 'Olivia', camera: null, microphone: null, screen: null },
            { identity: 's:abc', kind: 'spectator', id: 'abc', camera: null, microphone: null, screen: null },
        ]);

        assert.deepEqual(stage.map((tile) => [tile.kind, tile.identity]), [['guest', 'p:olivia'], ['cohost', 'm:host2'], ['screen', 'm:host2']]);
        assert.equal(stage[0].video, camera);
        assert.equal(stage[0].audio, microphone);
        assert.equal(stage[2].video, screen);
        assert.equal(stage[2].audio, null);
        assert.deepEqual(new Set(stage.map((tile) => tile.key)).size, 3, 'keys are unique');
        assert.deepEqual(stageOf(null), []);
    },

    async 'studio: the side lists - waiting, on air, earlier (newest first, a few)'() {
        const invites = mergeInvites(new Map(), [
            invite({ id: 'i-1', participantId: 'p-1', invitedAtMs: NOW - 30_000, expiresAtMs: NOW + 90_000 }),
            invite({ id: 'i-2', participantId: 'p-2', invitedAtMs: NOW - 50_000, expiresAtMs: NOW + 70_000 }),
            invite({ id: 'i-3', participantId: 'p-3', status: 'joined', invitedAtMs: NOW - 90_000 }),
            invite({ id: 'i-4', participantId: 'p-4', status: 'ended', invitedAtMs: NOW - 400_000, onAirFromMs: NOW - 390_000, onAirToMs: NOW - 300_000 }),
            invite({ id: 'i-5', participantId: 'p-5', status: 'invited', invitedAtMs: NOW - 300_000, expiresAtMs: NOW - 180_000 }),
            invite({ id: 'i-6', participantId: 'p-6', status: 'ended', invitedAtMs: NOW - 200_000 }),
        ]);

        const sections = studioSections(invites, NOW);
        assert.deepEqual(sections.waiting.map((entry) => entry.id), ['i-2', 'i-1'], 'the one running out first on top');
        assert.deepEqual(sections.onAir.map((entry) => entry.id), ['i-3']);
        assert.deepEqual(sections.earlier.map((entry) => [entry.id, entry.status]), [['i-6', 'withdrawn'], ['i-5', 'expired'], ['i-4', 'ended']]);
        assert.equal(studioSections(invites, NOW, 1).earlier.length, 1);
        assert.deepEqual(studioSections(null, NOW), { waiting: [], onAir: [], earlier: [] });

        // The invitation "Thank you" ends: the guest's open one
        assert.equal(inviteOfGuest(invites, 'P-3', NOW).id, 'i-3');
        assert.equal(inviteOfGuest(invites, 'p-1', NOW).id, 'i-1', 'joined, the JSON did not see the answer yet');
        assert.equal(inviteOfGuest(invites, 'p-4', NOW), null);
        assert.equal(inviteOfGuest(invites, 'nobody', NOW), null);
    },
};

const results = {};

for (const [name, scenario] of Object.entries(scenarios)) {
    try {
        await scenario();
        results[name] = 'ok';
    } catch (error) {
        results[name] = String(error?.stack ?? error);
    }
}

process.stdout.write(JSON.stringify(results));
