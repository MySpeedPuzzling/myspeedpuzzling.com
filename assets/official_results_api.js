/**
 * The JSON client of the official results tools (live entry, results desk, seating) - the rules of
 * `Services\OfficialResultsApi` on the server: JSON both ways, the stateless CSRF header, and never a silent
 * login redirect (docs/features/competitions-management/official-results.md).
 *
 * Every call resolves (never rejects) to one of:
 * - {kind: 'ok', status, data}          2xx with a JSON body
 * - {kind: 'auth', status}              signed out or the session expired (401, or any redirect) - keep unsent work
 * - {kind: 'forbidden', status, data}   403 from our endpoint (JSON `error`: no edit rights, or an invalid CSRF token)
 * - {kind: 'client', status, data}      other 4xx from our endpoint - an answer the person must act on (JSON body)
 * - {kind: 'server', status, retryAfter, busy}  retry later: 5xx, and anything that is not our endpoint's answer or
 *                                       asks to come back - 408 / 425 / 429, and every 4xx without our JSON (a rate
 *                                       limiter's or a ban page, a proxy). `busy` is true for those (the whole server
 *                                       is unavailable for a while, not one change); `retryAfter` = ms from the
 *                                       Retry-After header, or null
 * - {kind: 'offline'}                   the request never reached the server - retry later
 *
 * isGone(answer) tells a `client` answer that the round or the event no longer exists (our JSON 404) - the page says so
 * and stops asking, never retries.
 */

// The request may simply go again: Request Timeout, Too Early, Too Many Requests
const RETRYABLE_STATUSES = [408, 425, 429];

/**
 * Retry-After in milliseconds (seconds or an HTTP date), null when absent or unreadable.
 *
 * @param {string|null} header
 * @param {number} now
 */
export function retryAfterMs(header, now = Date.now()) {
    if (typeof header !== 'string' || header.trim() === '') {
        return null;
    }

    if (/^\d+$/.test(header.trim())) {
        return parseInt(header.trim(), 10) * 1000;
    }

    const date = Date.parse(header);

    return Number.isNaN(date) ? null : Math.max(0, date - now);
}

/**
 * @param {Response} response
 * @returns {Promise<object|null>}
 */
async function readJson(response) {
    const type = response.headers.get('Content-Type') || '';

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
 * @param {string} url
 * @param {{method?: string, body?: object, csrfToken?: string, signal?: AbortSignal}} options
 */
export async function officialResultsRequest(url, { method = 'GET', body = undefined, csrfToken = '', signal = undefined } = {}) {
    const headers = { Accept: 'application/json' };

    if (method !== 'GET') {
        headers['Content-Type'] = 'application/json';
        headers['X-CSRF-Token'] = csrfToken;
    }

    let response;

    try {
        response = await fetch(url, {
            method,
            headers,
            credentials: 'same-origin',
            // A redirect is always the login page - it must never count as a saved answer
            redirect: 'manual',
            cache: 'no-store',
            body: method === 'GET' ? undefined : JSON.stringify(body ?? {}),
            signal,
        });
    } catch (e) {
        return { kind: 'offline' };
    }

    if (response.type === 'opaqueredirect' || (response.status >= 300 && response.status < 400) || response.status === 401) {
        return { kind: 'auth', status: response.status };
    }

    const data = await readJson(response);

    if (response.ok) {
        if (data === null) {
            // A 2xx page instead of JSON (a proxy, a login page served without a redirect) is not a saved answer
            return { kind: 'auth', status: response.status };
        }

        return { kind: 'ok', status: response.status, data };
    }

    const retryAfter = retryAfterMs(response.headers.get('Retry-After'));

    if (response.status >= 500) {
        return { kind: 'server', status: response.status, retryAfter, busy: false };
    }

    // Our endpoints answer every refusal with a JSON `error` - anything else (a rate limiter, a ban page, a proxy) or
    // an answer asking to come back is no verdict on the change: sent again later
    if (RETRYABLE_STATUSES.includes(response.status) || typeof data?.error !== 'string') {
        return { kind: 'server', status: response.status, retryAfter, busy: true };
    }

    if (response.status === 403) {
        return { kind: 'forbidden', status: 403, data };
    }

    return { kind: 'client', status: response.status, data };
}

/**
 * The round or the event of the request does not exist (any more - deleted while the page was open): the endpoints'
 * JSON 404 `round_not_found` / `competition_not_found` (OfficialResultsApiNotFoundSubscriber).
 *
 * @param {object} answer what officialResultsRequest() resolved to
 */
export function isGone(answer) {
    return answer?.kind === 'client'
        && answer.status === 404
        && (answer.data?.error === 'round_not_found' || answer.data?.error === 'competition_not_found');
}

/**
 * A UUID for client change ids and entries typed in at the venue (crypto.randomUUID needs a secure context;
 * the fallback is a v4 from getRandomValues).
 */
export function newClientId() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
