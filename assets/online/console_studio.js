/**
 * The host's studio page of an online round (docs/features/online-competitions/README.md §5.12 "The studio flow",
 * contract phase-1b.md §4) - its pure parts, no DOM, pinned by tests/online-console-harness.mjs:
 *
 * - audienceOf(): how many watch - spectators join the studio hidden (LiveKitTokens::spectator()), so nobody sees them
 *   in the room; LiveKit's room count includes them (checked against LiveKit 1.13.9, spikes.md S6), everybody else is
 *   visible: the count minus the visible ones and the host;
 * - stageOf(): who is on the stage - the guests (participants' camera devices), the co-hosts, screens shared;
 * - studioSections(): the side lists - waiting for an answer, on air, earlier.
 */

import { inviteStatusAt } from './console_invites.js';

/**
 * @param {number} numParticipants LiveKit's count of the room (Room.numParticipants - hidden ones included, updated
 *        every few seconds)
 * @param {number} visibleOthers the remote participants this page sees
 */
export function audienceOf(numParticipants, visibleOthers) {
    if (!Number.isFinite(numParticipants) || numParticipants <= 0) {
        return 0;
    }

    return Math.max(0, Math.floor(numParticipants) - Math.max(0, Math.floor(visibleOthers || 0)) - 1);
}

/**
 * The stage: every guest (`p:{participantId}` - their camera and microphone), every co-host (`m:…`), and each screen
 * shared as a tile of its own - guests first (in the order they are listed), then co-hosts, then screens.
 *
 * @param {Array<{identity: string, kind: string, id: string|null, name?: string, camera: object|null, microphone: object|null, screen: object|null}>} list
 *        the room's others (livekit_session.js participantsOf())
 * @returns {Array<{key: string, kind: 'guest'|'cohost'|'screen', identity: string, id: string|null, name: string, video: object|null, audio: object|null}>}
 */
export function stageOf(list) {
    const guests = [];
    const cohosts = [];
    const screens = [];

    for (const entry of Array.isArray(list) ? list : []) {
        if (entry?.kind === 'participant') {
            guests.push({ key: `guest:${entry.identity}`, kind: 'guest', identity: entry.identity, id: entry.id, name: entry.name ?? '', video: entry.camera ?? null, audio: entry.microphone ?? null });
        } else if (entry?.kind === 'moderator') {
            cohosts.push({ key: `cohost:${entry.identity}`, kind: 'cohost', identity: entry.identity, id: entry.id, name: entry.name ?? '', video: entry.camera ?? null, audio: entry.microphone ?? null });
        }

        if ((entry?.kind === 'participant' || entry?.kind === 'moderator') && entry.screen) {
            screens.push({ key: `screen:${entry.identity}`, kind: 'screen', identity: entry.identity, id: entry.id, name: entry.name ?? '', video: entry.screen, audio: null });
        }
    }

    return [...guests, ...cohosts, ...screens];
}

/**
 * The side lists from the round's invitations (console_invites.js map): waiting for an answer (oldest first - the one
 * running out first), on air (joined, oldest first), earlier (the rest, newest first, at most `earlierLimit`).
 *
 * @param {Map<string, object>} invites
 */
export function studioSections(invites, serverNowMs, earlierLimit = 5) {
    const waiting = [];
    const onAir = [];
    const earlier = [];

    for (const invite of invites instanceof Map ? invites.values() : []) {
        const status = inviteStatusAt(invite, serverNowMs);

        if (status === 'invited') {
            waiting.push(invite);
        } else if (status === 'joined') {
            onAir.push(invite);
        } else if (status !== null) {
            // Ended before anybody was on air: withdrawn
            earlier.push({ ...invite, status: status === 'ended' && !Number.isFinite(invite.onAirFromMs) ? 'withdrawn' : status });
        }
    }

    const byInvited = (a, b) => (a.invitedAtMs ?? 0) - (b.invitedAtMs ?? 0);
    waiting.sort(byInvited);
    onAir.sort(byInvited);
    earlier.sort((a, b) => byInvited(b, a));

    return { waiting, onAir, earlier: earlier.slice(0, Math.max(0, earlierLimit)) };
}

/**
 * The invitation of a guest in the room - the one to end with "Thank you" (open: `joined`, or `invited` while the
 * studio JSON did not see the answer yet)
 */
export function inviteOfGuest(invites, participantId, serverNowMs) {
    const invite = invites instanceof Map && typeof participantId === 'string' ? invites.get(participantId.toLowerCase()) : null;
    const status = inviteStatusAt(invite ?? null, serverNowMs);

    return status === 'joined' || status === 'invited' ? invite : null;
}
