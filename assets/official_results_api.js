/**
 * The JSON client of the official results tools (live entry, results desk, seating) - the rules of
 * `Services\OfficialResultsApi` on the server: JSON both ways, the stateless CSRF header, and never a silent
 * login redirect (docs/features/competitions-management/official-results.md).
 *
 * Every call resolves (never rejects) to one of:
 * - {kind: 'ok', status, data}          2xx with a JSON body
 * - {kind: 'auth', status}              signed out or the session expired (401, or any redirect) - keep unsent work
 * - {kind: 'forbidden', status, data}   403 (no edit rights, or an invalid CSRF token)
 * - {kind: 'client', status, data}      other 4xx - a validation answer the person must act on (data = JSON body)
 * - {kind: 'server', status}            5xx - retry later
 * - {kind: 'offline'}                   the request never reached the server - retry later
 */

/**
 * @param {Response} response
 * @returns {Promise<object|null>}
 */
async function readJson(response) {
    const type = response.headers.get('Content-Type') || '';

    if (!type.includes('application/json')) {
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

    if (response.status === 403) {
        return { kind: 'forbidden', status: 403, data };
    }

    if (response.status >= 500) {
        return { kind: 'server', status: response.status };
    }

    return { kind: 'client', status: response.status, data };
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
