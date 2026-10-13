/**
 * What the moderators' console (console_video.js, the hall) and the host's studio page (online_studio_controller.js,
 * the studio) share (docs/features/online-competitions/contracts/phase-1b.md stream VM):
 *
 * - RoomLink: a moderator's connection to one room of the round - the token of `online_video_token`, livekit_session.js
 *   joinRoom(), tried again with a growing wait when it fails or drops; never again by itself when the same moderator's
 *   video opened in another tab took over (LiveKit's DUPLICATE_IDENTITY) or when the server says there is no video
 *   (`video_unavailable`), signed out, or forbidden.
 * - StudioInvites: the round's invitations to the studio (console_invites.js), read from the studio's JSON
 *   (`online_studio` asked for JSON) - often while one is open, rarely otherwise, at once on a hint - and from the
 *   answers of `online_studio_invite` / `online_studio_end`.
 * - studioRefusal(): the text key of a refused invite / end.
 */

import { joinRoom } from './livekit_session.js';
import { officialResultsRequest } from '../official_results_api.js';
import { mergeInvites, studioPollDelay } from './console_invites.js';

// LiveKit's DisconnectReason values that change what happens next
export const CLIENT_INITIATED = 1;
export const DUPLICATE_IDENTITY = 2;

// A connection that failed is tried again after these (the last one repeats)
export const RECONNECT_MS = [2000, 5000, 10000, 20000, 30000];

// Somebody's device came or went: the studio's JSON this soon after
export const STUDIO_HINT_MS = 700;

export class RoomLink {
    /**
     * @param {object} options
     * @param {string} options.tokenUrl          online_video_token
     * @param {'hall'|'studio'} options.room
     * @param {string} options.csrfToken         the stateless CSRF token of the organiser's JSON endpoints
     * @param {object} [options.joinOptions]     more of joinRoom()'s options (allowSubscribers, connectOptions, …)
     * @param {function(object): void} options.onSession   a session is there (joined)
     * @param {function(): void} options.onLost  the session is gone (before the next try)
     * @param {function(string): void} options.onStatus     connecting | connected | reconnecting | failed |
     *        duplicate | unavailable | sign_in | forbidden
     * @param {function(object): Promise<object>} [options.join]  joinRoom - replaced in tests
     */
    constructor({ tokenUrl, room, csrfToken, joinOptions = {}, onSession, onLost, onStatus, join = joinRoom }) {
        this.tokenUrl = tokenUrl;
        this.room = room;
        this.csrfToken = csrfToken;
        this.joinOptions = joinOptions;
        this.onSession = onSession;
        this.onLost = onLost;
        this.onStatus = onStatus;
        this.join = join;

        this.session = null;
        this.status = 'connecting';
        this.connecting = false;
        this.closed = false;
        this.failures = 0;
        this.timer = null;
        this.offs = [];
        this.disconnecting = false;
        this.disconnectReason = null;
    }

    setStatus(status) {
        this.status = status;
        this.onStatus?.(status);
    }

    /**
     * Asked by the person (after a takeover by another tab, say): tried at once, from the first wait again
     */
    reconnectNow() {
        this.failures = 0;
        this.connect();
    }

    async connect() {
        if (this.closed || this.connecting || this.session !== null) {
            return;
        }

        this.connecting = true;
        window.clearTimeout(this.timer);
        this.setStatus(this.failures === 0 ? 'connecting' : 'reconnecting');

        const answer = await officialResultsRequest(this.tokenUrl, { method: 'POST', body: { room: this.room }, csrfToken: this.csrfToken });

        if (this.closed) {
            this.connecting = false;

            return;
        }

        if (answer.kind !== 'ok' || typeof answer.data?.token !== 'string') {
            this.connecting = false;

            if (answer.kind === 'auth') {
                this.setStatus('sign_in');
            } else if (answer.kind === 'forbidden') {
                this.setStatus('forbidden');
            } else if (answer.kind === 'client') {
                // video_unavailable (turned off meanwhile), round_not_online, round_not_found: nothing to try again
                this.setStatus('unavailable');
            } else {
                this.retry();
            }

            return;
        }

        let session;

        try {
            session = await this.join({ url: answer.data.url, token: answer.data.token, ...this.joinOptions });
        } catch (e) {
            this.connecting = false;
            this.retry();

            return;
        }

        this.connecting = false;

        if (this.closed) {
            session.leave().catch(() => {});

            return;
        }

        this.session = session;
        this.failures = 0;
        this.offs.push(session.onConnectionState(({ state, reason }) => this.connectionChanged(state, reason)));
        this.setStatus('connected');
        this.onSession?.(session);
    }

    retry() {
        const wait = RECONNECT_MS[Math.min(this.failures, RECONNECT_MS.length - 1)];
        this.failures += 1;
        this.setStatus('failed');
        window.clearTimeout(this.timer);
        this.timer = window.setTimeout(() => this.connect(), wait);
    }

    connectionChanged(state, reason) {
        if (this.closed || this.session === null) {
            return;
        }

        if (state === 'reconnecting' || state === 'signalReconnecting') {
            this.setStatus('reconnecting');

            return;
        }

        if (state === 'connected') {
            this.setStatus('connected');

            return;
        }

        if (state !== 'disconnected') {
            return;
        }

        if (reason !== null && reason !== undefined) {
            this.disconnectReason = reason;
        }

        if (this.disconnecting) {
            return;
        }

        // LiveKit reports the state first and the reason right after it (both at once) - decided once both are in
        this.disconnecting = true;
        Promise.resolve().then(() => {
            const why = this.disconnectReason;
            this.disconnecting = false;
            this.disconnectReason = null;

            if (this.closed || this.session === null) {
                return;
            }

            this.drop();

            if (why === DUPLICATE_IDENTITY) {
                // The same moderator's video opened in another tab or window took over - this one waits to be asked
                this.setStatus('duplicate');
            } else if (why !== CLIENT_INITIATED) {
                this.retry();
            }
        });
    }

    /**
     * The session goes (LiveKit dropped it, or the page leaves): the owner lets go of what it holds first
     */
    drop() {
        const session = this.session;

        if (session === null) {
            return;
        }

        this.offs.splice(0).forEach((off) => off());
        this.onLost?.(session);
        this.session = null;
        session.leave().catch(() => {});
    }

    close() {
        this.closed = true;
        window.clearTimeout(this.timer);
        this.drop();
    }
}

export class StudioInvites {
    /**
     * @param {object} options
     * @param {string} options.url               online_studio (asked for JSON)
     * @param {function(): number} options.serverNow
     * @param {function(Map): void} options.onChange
     * @param {function(number, number, number): void} [options.onServerTime]  each answer's serverNowMs with the local
     *        Date.now() before and after the request - a clock sample
     */
    constructor({ url, serverNow, onChange, onServerTime = null }) {
        this.url = url;
        this.serverNow = serverNow;
        this.onChange = onChange;
        this.onServerTime = onServerTime;
        this.invites = new Map();
        this.polling = false;
        this.closed = false;
        this.timers = { poll: null, hint: null };
        this.onVisibility = () => {
            if (document.visibilityState === 'visible') {
                this.refresh();
            }
        };
    }

    start() {
        document.addEventListener('visibilitychange', this.onVisibility);
        this.refresh();
    }

    close() {
        this.closed = true;
        window.clearTimeout(this.timers.poll);
        window.clearTimeout(this.timers.hint);
        document.removeEventListener('visibilitychange', this.onVisibility);
    }

    merge(incoming) {
        this.invites = mergeInvites(this.invites, incoming);
        this.onChange?.(this.invites);
    }

    hint() {
        window.clearTimeout(this.timers.hint);
        this.timers.hint = window.setTimeout(() => this.refresh(), STUDIO_HINT_MS);
    }

    schedule() {
        window.clearTimeout(this.timers.poll);

        if (!this.closed) {
            this.timers.poll = window.setTimeout(() => this.refresh(), studioPollDelay(this.invites, this.serverNow()));
        }
    }

    async refresh() {
        if (this.closed) {
            return;
        }

        if (this.polling || document.visibilityState !== 'visible') {
            this.schedule();

            return;
        }

        this.polling = true;
        const sentAt = Date.now();
        const answer = await officialResultsRequest(this.url);
        const receivedAt = Date.now();
        this.polling = false;

        if (this.closed) {
            return;
        }

        if (answer.kind === 'ok' && Number.isFinite(answer.data?.serverNowMs)) {
            this.onServerTime?.(answer.data.serverNowMs, sentAt, receivedAt);
        }

        if (answer.kind === 'ok' && Array.isArray(answer.data?.invites)) {
            this.merge(answer.data.invites);
        }

        this.schedule();
    }
}

/**
 * The text key (after `…_error_`) of a refused invite / end, from what officialResultsRequest() resolved to
 */
export function studioRefusal(answer) {
    if (answer?.kind === 'auth') {
        return 'sign_in';
    }

    if (answer?.kind === 'forbidden') {
        return 'forbidden';
    }

    if (answer?.kind === 'offline') {
        return 'offline';
    }

    const code = typeof answer?.data?.error === 'string' ? answer.data.error : '';

    return ['already_invited', 'not_entered', 'participant_not_found', 'video_unavailable', 'invite_not_found', 'round_not_online'].includes(code)
        ? code
        : 'failed';
}

/**
 * "Withdraw" / "Thank you": `online_studio_end` of the invitation (its id in place of the placeholder)
 */
export function endInvite({ url, placeholder, csrfToken }, invite) {
    return officialResultsRequest(String(url).replace(placeholder, invite.id), { method: 'POST', body: {}, csrfToken });
}

/**
 * "Invite to the studio": `online_studio_invite`
 */
export function sendInvite({ url, csrfToken }, participantId) {
    return officialResultsRequest(url, { method: 'POST', body: { participantId }, csrfToken });
}
