/**
 * Claim invitations in the participants sheet (docs/features/competitions-management/participant-linking.md §5.5,
 * §7.1, R8): the e-mail with the person's personal claim link, to the address the organiser keeps in the E-mail column.
 * The row's "Send invitation" and the bulk bar's "Send invitations to N" - one endpoint, POST
 * `participants_sheet_claim_invitations` {participants: [ids]}, answering what went out and what was skipped, by reason.
 *
 * - invitationSkip(person, now): why the person gets no invitation now - the server's rule
 *   (CompetitionParticipant::claimInvitationRefusal()): not on the list, linked, no e-mail, said no, invited in the last
 *   24 hours; null = they may get one. The server also applies "said no" and the 24 hours per address over the event
 *   (a person removed and added again), and counts an address that takes no e-mail from us as sent (D10);
 * - invitationPlan(people, now): {eligible: [ids], skipped: {reason: count}} for the confirmation;
 * - requestInvitations(endpoint, ids): the call, resolving (never rejecting) to {ok: true, sent, skipped} or
 *   {ok: false, reason, message}.
 *
 * Pure except requestInvitations() (network through official_results_api.js) - pinned by
 * tests/participants-sheet-people/claim-invitations.mjs.
 */

import { officialResultsRequest } from '../official_results_api.js';

// CompetitionParticipant::CLAIM_INVITATION_EVERY
export const INVITATION_EVERY_MS = 24 * 60 * 60 * 1000;
export const SKIP_REASONS = ['not_on_list', 'linked', 'no_email', 'said_no', 'invited_recently'];

/**
 * @param {object} person a state row
 * @param {number} now milliseconds
 * @returns {string|null}
 */
export function invitationSkip(person, now = Date.now()) {
    if (!person || (person.removedAt ?? null) !== null) {
        return 'not_on_list';
    }

    if ((person.player ?? null) !== null) {
        return 'linked';
    }

    if (typeof person.email !== 'string' || person.email === '') {
        return 'no_email';
    }

    if (person.invitationsRefused === true) {
        return 'said_no';
    }

    const invitedAt = person.invitedAt ? Date.parse(person.invitedAt) : NaN;

    if (!Number.isNaN(invitedAt) && invitedAt > now - INVITATION_EVERY_MS) {
        return 'invited_recently';
    }

    return null;
}

/**
 * @param {object[]} people
 * @param {number} now
 * @returns {{eligible: string[], skipped: Object<string, number>}}
 */
export function invitationPlan(people, now = Date.now()) {
    const eligible = [];
    const skipped = {};

    for (const person of people) {
        const reason = invitationSkip(person, now);

        if (reason === null) {
            eligible.push(person.id);
        } else {
            skipped[reason] = (skipped[reason] ?? 0) + 1;
        }
    }

    return { eligible, skipped };
}

/**
 * @param {{url: string, csrfToken: string, request?: function}} endpoint
 * @param {string[]} participantIds
 * @returns {Promise<{ok: true, sent: number, skipped: Object<string, number>}|{ok: false, reason: string, message: string|null}>}
 */
export async function requestInvitations({ url, csrfToken, request = officialResultsRequest }, participantIds) {
    const answer = await request(url, { method: 'POST', body: { participants: participantIds }, csrfToken });

    if (answer.kind === 'ok') {
        const data = answer.data ?? {};

        if (Number.isInteger(data.sent) && data.skipped !== null && typeof data.skipped === 'object') {
            const skipped = {};

            for (const reason of SKIP_REASONS) {
                if (Number.isInteger(data.skipped[reason]) && data.skipped[reason] > 0) {
                    skipped[reason] = data.skipped[reason];
                }
            }

            return { ok: true, sent: data.sent, skipped };
        }

        return { ok: false, reason: 'server', message: null };
    }

    if (answer.kind === 'client' || answer.kind === 'forbidden') {
        return { ok: false, reason: typeof answer.data?.error === 'string' ? answer.data.error : answer.kind, message: typeof answer.data?.message === 'string' ? answer.data.message : null };
    }

    return { ok: false, reason: answer.kind, message: null };
}
