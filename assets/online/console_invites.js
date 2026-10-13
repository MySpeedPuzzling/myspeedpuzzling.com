/**
 * The studio's invitations as the moderators' console and the host's studio page keep them (docs/features/
 * online-competitions/contracts/phase-1b.md §4, §8) - pure, no DOM, pinned by tests/online-console-harness.mjs.
 *
 * Every participant's latest invitation, by participant id, from the answers of `online_studio_invite` /
 * `online_studio_end` and of the studio's JSON (`online_studio`, asked for JSON): {id, participantId, status,
 * invitedAtMs, expiresAtMs, answeredAtMs?, onAirFromMs, onAirToMs, name?}.
 *
 * - An invitation only moves forward (invited → joined → ended; invited → declined / expired / ended): two answers about
 *   the same one keep the later status, whichever arrived last - a slow poll never takes a "Thank you" back.
 * - Another invitation of the same participant replaces it when it is newer (invited later).
 * - `invited` past its expiry is `expired` (StudioInvite::statusAt() on the server, as of the server's time here).
 */

// Polling the studio's JSON: often while an invitation waits for its answer or a guest is on air, rarely otherwise
// (another moderator's invitation, a guest leaving by themselves)
export const OPEN_POLL_MS = 3000;
export const IDLE_POLL_MS = 20000;

const RANK = { invited: 0, joined: 1, declined: 2, expired: 2, ended: 2 };

function isInvite(invite) {
    return invite !== null
        && typeof invite === 'object'
        && typeof invite.id === 'string'
        && typeof invite.participantId === 'string'
        && typeof invite.status === 'string'
        && Object.prototype.hasOwnProperty.call(RANK, invite.status);
}

function finite(value) {
    return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

function invitedAt(invite) {
    return finite(invite.invitedAtMs) ?? -Infinity;
}

/**
 * The status as of the server's time: an invitation nobody answered in time is expired
 */
export function inviteStatusAt(invite, serverNowMs) {
    if (!isInvite(invite)) {
        return null;
    }

    const expiresAtMs = finite(invite.expiresAtMs);

    if (invite.status === 'invited' && expiresAtMs !== null && Number.isFinite(serverNowMs) && serverNowMs >= expiresAtMs) {
        return 'expired';
    }

    return invite.status;
}

/**
 * The participant's latest invitation from what was known and what just arrived
 */
export function mergeInvite(known, incoming) {
    if (!isInvite(incoming)) {
        return isInvite(known) ? known : null;
    }

    if (!isInvite(known)) {
        return { ...incoming };
    }

    if (known.id !== incoming.id) {
        const newer = invitedAt(incoming) !== invitedAt(known) ? invitedAt(incoming) > invitedAt(known) : incoming.id > known.id;

        return newer ? { ...incoming } : known;
    }

    // The same invitation: never backwards
    const status = RANK[incoming.status] >= RANK[known.status] ? incoming.status : known.status;

    return {
        ...known,
        ...incoming,
        status,
        name: incoming.name ?? known.name,
        country: incoming.country ?? known.country,
        answeredAtMs: finite(incoming.answeredAtMs) ?? finite(known.answeredAtMs),
        onAirFromMs: finite(incoming.onAirFromMs) ?? finite(known.onAirFromMs),
        onAirToMs: finite(incoming.onAirToMs) ?? finite(known.onAirToMs),
    };
}

/**
 * A new map with the invitation(s) merged in, by participant
 *
 * @param {Map<string, object>} invites
 * @param {object|object[]} incoming one invitation or a list
 * @returns {Map<string, object>}
 */
export function mergeInvites(invites, incoming) {
    const next = new Map(invites instanceof Map ? invites : []);

    for (const invite of Array.isArray(incoming) ? incoming : [incoming]) {
        if (isInvite(invite)) {
            const participantId = invite.participantId.toLowerCase();
            next.set(participantId, mergeInvite(next.get(participantId) ?? null, { ...invite, participantId }));
        }
    }

    return next;
}

/**
 * What a participant's tile offers about the studio:
 * - status: the invitation's as of now, null when never invited; `withdrawn` for one ended before anybody was on air;
 * - open: waiting for the answer or on air (another invitation is refused meanwhile);
 * - onAir: LiveKit saw them in the studio (the webhook) and they have not left;
 * - canInvite: a finisher without an open invitation; canEnd: an open one ("Withdraw" / "Thank you");
 * - remainingMs: how long the invitation still waits for the answer.
 */
export function inviteView(invite, serverNowMs, finished = false) {
    let status = inviteStatusAt(invite, serverNowMs);
    const open = status === 'invited' || status === 'joined';

    if (status === 'ended' && finite(invite.onAirFromMs) === null) {
        status = 'withdrawn';
    }

    return {
        status,
        open,
        onAir: status === 'joined' && finite(invite.onAirFromMs) !== null && finite(invite.onAirToMs) === null,
        canInvite: finished === true && !open,
        canEnd: open,
        remainingMs: status === 'invited' && Number.isFinite(serverNowMs) ? Math.max(0, finite(invite.expiresAtMs) - serverNowMs) : null,
    };
}

/**
 * Something is happening in the studio: an invitation waits for its answer, or a guest is (going) on air
 */
export function hasOpenInvite(invites, serverNowMs) {
    for (const invite of invites instanceof Map ? invites.values() : []) {
        const status = inviteStatusAt(invite, serverNowMs);

        if (status === 'invited' || status === 'joined') {
            return true;
        }
    }

    return false;
}

export function studioPollDelay(invites, serverNowMs) {
    return hasOpenInvite(invites, serverNowMs) ? OPEN_POLL_MS : IDLE_POLL_MS;
}

/**
 * "1:42" - the time an invitation still waits for its answer
 */
export function formatRemaining(ms) {
    const seconds = Number.isFinite(ms) && ms > 0 ? Math.ceil(ms / 1000) : 0;

    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
}
