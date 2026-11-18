// Claim invitations in the participants sheet (assets/participants_sheet/claim_invitations.js, participant-linking.md
// §5.5, §7.1, R8): why a person gets none (the server's rule), the bulk plan with its skip counts and the endpoint call.
import assert from 'node:assert/strict';
import { person } from '../participants-sheet-core/fixture.mjs';
import { INVITATION_EVERY_MS, invitationPlan, invitationSkip, requestInvitations } from '../../assets/participants_sheet/claim_invitations.js';

const linked = { id: 'pl-1', visible: true, name: 'Kim', code: 'kim', country: null, avatar: null, profileUrl: null };
const NOW = Date.parse('2026-10-14T12:00:00Z');

function endpoint(answer) {
    const calls = [];

    return {
        calls,
        url: '/en/participants-sheet-api/c1/claim-invitations',
        csrfToken: 'csrf-1',
        request: async (url, options) => {
            calls.push({ url, ...options });

            return answer;
        },
    };
}

export default function (test) {
    test('somebody on the list, not linked, with an address, never invited may get one', () => {
        assert.equal(invitationSkip(person('a', 'Ana', { email: 'ana@example.test' }), NOW), null);
    });

    test('the reasons, in the server\'s order', () => {
        assert.equal(invitationSkip(null, NOW), 'not_on_list');
        assert.equal(invitationSkip(person('a', 'Ana', { email: 'a@x.test', removedAt: '2026-10-01T10:00:00+00:00' }), NOW), 'not_on_list');
        assert.equal(invitationSkip(person('a', 'Ana', { email: 'a@x.test', player: linked }), NOW), 'linked');
        assert.equal(invitationSkip(person('a', 'Ana'), NOW), 'no_email');
        assert.equal(invitationSkip(person('a', 'Ana', { email: '' }), NOW), 'no_email');
        assert.equal(invitationSkip(person('a', 'Ana', { email: 'a@x.test', invitationsRefused: true }), NOW), 'said_no');
    });

    test('one invitation per person in 24 hours', () => {
        const at = (ms) => new Date(ms).toISOString();

        assert.equal(invitationSkip(person('a', 'Ana', { email: 'a@x.test', invitedAt: at(NOW - 2 * 60 * 60 * 1000) }), NOW), 'invited_recently');
        assert.equal(invitationSkip(person('a', 'Ana', { email: 'a@x.test', invitedAt: at(NOW - INVITATION_EVERY_MS) }), NOW), null);
        assert.equal(invitationSkip(person('a', 'Ana', { email: 'a@x.test', invitedAt: at(NOW - 3 * INVITATION_EVERY_MS) }), NOW), null);
    });

    test('the plan of the bulk bar: who gets one and the skip counts', () => {
        const plan = invitationPlan([
            person('a', 'Ana', { email: 'a@x.test' }),
            person('b', 'Ben', { email: 'b@x.test' }),
            person('c', 'Cara'),
            person('d', 'Dan'),
            person('e', 'Eva', { email: 'e@x.test', player: linked }),
        ], NOW);

        assert.deepEqual(plan, { eligible: ['a', 'b'], skipped: { no_email: 2, linked: 1 } });
    });

    test('the endpoint is asked with the people and the CSRF token; its answer read, unknown reasons dropped', async () => {
        const server = endpoint({ kind: 'ok', status: 200, data: { sent: 2, skipped: { invited_recently: 1, said_no: 1, something: 4 } } });

        assert.deepEqual(await requestInvitations(server, ['a', 'b', 'c', 'd']), { ok: true, sent: 2, skipped: { said_no: 1, invited_recently: 1 } });
        assert.deepEqual(server.calls[0], { url: '/en/participants-sheet-api/c1/claim-invitations', method: 'POST', body: { participants: ['a', 'b', 'c', 'd'] }, csrfToken: 'csrf-1' });
    });

    test('a refusal comes back with its reason and message, a broken answer as a server failure', async () => {
        assert.deepEqual(
            await requestInvitations(endpoint({ kind: 'client', status: 409, data: { error: 'not_public', message: 'Not yet' } }), ['a']),
            { ok: false, reason: 'not_public', message: 'Not yet' },
        );
        assert.deepEqual(await requestInvitations(endpoint({ kind: 'ok', status: 200, data: { sent: 'x' } }), ['a']), { ok: false, reason: 'server', message: null });
        assert.deepEqual(await requestInvitations(endpoint({ kind: 'network' }), ['a']), { ok: false, reason: 'network', message: null });
    });
}
