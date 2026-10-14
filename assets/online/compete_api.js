/**
 * The participant page's requests (docs/features/online-competitions/implementation-plan.md §3.1, contract §6): the
 * personal token in the path is the only credential - no cookies, no CSRF token, nothing that could start a session.
 * Every JSON answer carries `serverNowMs`, so every request is also a free clock sample (clock_sync.js observe()).
 *
 * Every call resolves (never rejects) to an answer the outboxes read (official_results_api.js shapes):
 * - {kind: 'ok', status, data}                  2xx with a JSON body
 * - {kind: 'client', status, data}              our JSON refusal (`{"error": code}`): unknown_link, invalid_json, …
 * - {kind: 'forbidden', status, data}           403 with our JSON
 * - {kind: 'server', status, retryAfter, busy}  5xx; and 408 / 425 / 429 or any 4xx without our JSON (a proxy, a ban
 *                                               page): `busy`, with Retry-After in ms when given
 * - {kind: 'auth', status}                      a redirect or a 2xx page that is not our JSON (a captive portal)
 * - {kind: 'offline'}                           no answer (no network, a timeout)
 *
 * connect() is the one call with the visitor's cookies: the signed-in "connect to my profile" POST (README §5.4) on the
 * site's main firewall, with its stateless CSRF token.
 *
 * fetch, the clocks and the abort timer are injected - pinned by tests/online-compete-harness.mjs.
 */

import { retryAfterMs } from '../official_results_api.js';

export const REQUEST_TIMEOUT_MS = 15000;
const RETRYABLE_STATUSES = [408, 425, 429];
// Stands for the invitation's id in the studio answer's URL (CompetePageController::STUDIO_INVITE_PLACEHOLDER)
export const STUDIO_INVITE_PLACEHOLDER = '00000000-0000-0000-0000-000000000000';

async function readJson(response) {
    const type = response?.headers?.get?.('Content-Type') ?? '';

    if (!type.includes('application/json') && !type.includes('+json')) {
        return null;
    }

    try {
        return await response.json();
    } catch (e) {
        return null;
    }
}

/**
 * The answer of a response (and its JSON body, already read).
 */
export function readCompeteAnswer(response, data, now = Date.now()) {
    if (response === null || response === undefined) {
        return { kind: 'offline' };
    }

    if (response.type === 'opaqueredirect' || (response.status >= 300 && response.status < 400)) {
        return { kind: 'auth', status: response.status };
    }

    if (response.ok) {
        return data !== null && typeof data === 'object'
            ? { kind: 'ok', status: response.status, data }
            : { kind: 'auth', status: response.status };
    }

    const retryAfter = retryAfterMs(response.headers?.get?.('Retry-After') ?? null, now);

    if (response.status >= 500) {
        return { kind: 'server', status: response.status, retryAfter, busy: false };
    }

    if (RETRYABLE_STATUSES.includes(response.status) || typeof data?.error !== 'string') {
        return { kind: 'server', status: response.status, retryAfter, busy: true };
    }

    if (response.status === 403) {
        return { kind: 'forbidden', status: 403, data };
    }

    return { kind: 'client', status: response.status, data };
}

/**
 * The organiser replaced the link (or removed the participant): nothing this page sends will ever be taken.
 */
export function isDeadLink(answer) {
    return (answer?.kind === 'client' || answer?.kind === 'forbidden') && answer.data?.error === 'unknown_link';
}

/**
 * @param {object} options
 * @param {{state: string, heartbeat: string, events: string, media: string, connect?: string|null, videoToken?: string|null, studioAnswer?: string|null}} options.urls
 * @param {function(string, object): Promise<Response>} [options.fetch]
 * @param {function(): number} [options.now]       the monotonic clock (performance.now)
 * @param {function(): number} [options.wallNow]   Date.now - a sample the phone slept through is refused by the clock
 * @param {function({t0: number, t1: number, w0: number, w1: number, serverNowMs: number}, string): void} [options.onSample]
 *        a clock sample (clock_sync.js observe()) and the name of the request it came with
 * @param {function(string, object): void} [options.onAnswer]  every answer, by request name (the connection status)
 * @param {number} [options.timeoutMs]
 */
export function createCompeteApi({
    urls,
    fetch: request = (...args) => globalThis.fetch(...args),
    now = () => globalThis.performance.now(),
    wallNow = () => Date.now(),
    onSample = () => {},
    onAnswer = () => {},
    timeoutMs = REQUEST_TIMEOUT_MS,
    schedule = (task, ms) => setTimeout(task, ms),
    cancel = (timer) => clearTimeout(timer),
}) {
    async function call(name, url, init) {
        if (typeof url !== 'string' || url === '') {
            return { kind: 'offline' };
        }

        const controller = typeof AbortController === 'undefined' ? null : new AbortController();
        const timer = controller === null ? null : schedule(() => controller.abort(), timeoutMs);
        const t0 = now();
        const w0 = wallNow();
        let answer;

        try {
            const response = await request(url, { ...init, signal: controller?.signal });
            // Headers in: the server answered - the body only adds download time to the round trip
            const t1 = now();
            const w1 = wallNow();
            const data = await readJson(response);

            if (Number.isFinite(data?.serverNowMs)) {
                try {
                    onSample({ t0, t1, w0, w1, serverNowMs: data.serverNowMs }, name);
                } catch (e) {
                    // The clock must never break a request
                }
            }

            answer = readCompeteAnswer(response, data, w1);
        } catch (e) {
            answer = { kind: 'offline' };
        } finally {
            if (timer !== null) {
                cancel(timer);
            }
        }

        try {
            onAnswer(name, answer);
        } catch (e) {
            // A listener must never break a request
        }

        return answer;
    }

    function json(name, url, body) {
        return call(name, url, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify(body ?? {}),
            credentials: 'omit',
            cache: 'no-store',
            redirect: 'manual',
        });
    }

    return {
        state() {
            return call('state', urls.state, {
                method: 'GET',
                headers: { Accept: 'application/json' },
                credentials: 'omit',
                cache: 'no-store',
                redirect: 'manual',
            });
        },

        heartbeat(body) {
            return json('heartbeat', urls.heartbeat, body);
        },

        events(body) {
            return json('events', urls.events, body);
        },

        media(body) {
            return json('media', urls.media, body);
        },

        /**
         * Live video (contract phase-1b.md §8): a token of a room of the round - `{deviceId, room: 'hall' | 'studio',
         * role: 'camera' | 'screen'}` → `{url, token, identity, room}`. No video parts on the page: never sent.
         */
        videoToken(body) {
            return json('videoToken', urls.videoToken ?? null, body);
        },

        /**
         * The answer to an invitation to the studio: 'join' | 'decline' → `{studio: {inviteId, status}}`
         * (`urls.studioAnswer` holds STUDIO_INVITE_PLACEHOLDER where the invitation's id goes).
         */
        studioAnswer({ inviteId, answer }) {
            const template = typeof urls.studioAnswer === 'string' ? urls.studioAnswer : '';
            const valid = typeof inviteId === 'string' && /^[0-9a-f-]{36}$/i.test(inviteId);

            return json('studioAnswer', template === '' || !valid ? null : template.replace(STUDIO_INVITE_PLACEHOLDER, inviteId), { answer });
        },

        /**
         * "Connect to my profile" (README §5.4) - the signed-in visitor's own cookies and the stateless CSRF token;
         * `confirm` = an organiser's explicit "This is me".
         */
        connect({ csrfToken = '', confirm = false } = {}) {
            const body = new URLSearchParams();
            body.set('_token', csrfToken);

            if (confirm) {
                body.set('confirm', '1');
            }

            return call('connect', urls.connect ?? null, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-Token': csrfToken },
                body,
                credentials: 'same-origin',
                cache: 'no-store',
                redirect: 'manual',
            });
        },
    };
}
