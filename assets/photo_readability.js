/**
 * A photo the phone cannot read (docs/features/photo-uploads/README.md "A photo the phone cannot read").
 *
 * 2026-11-14: a phone handed the add-result form a photo of 0 bytes. The background upload was refused (`empty`), the
 * photo was to go with the form instead, and every Save then died in the browser within 30-80 ms - the page blamed the
 * connection. Such a photo must never block a save: it is taken out of the form and the player is told.
 *
 * The decisions live here, without the DOM, so they run under node (tests/PhotoReadabilityScriptTest.php). Nothing in
 * this file throws: whenever a check cannot tell, the answer is "readable" / "the network" - what the form did before.
 */

// Reading one byte takes no time; a device that needs longer (a photo still coming from the cloud) is not judged
export const PROBE_TIMEOUT_MS = 1500;
// A save that dies this fast, online, never left the browser - the connection had no time to be slow
export const FAST_FAILURE_MS = 1000;
// ...and one that took this long was the connection
export const SLOW_FAILURE_MS = 10000;
export const ERROR_TEXT_MAX = 200;

const TIMED_OUT = Symbol('timed out');

export function isEmptyPhoto(file) {
    try {
        return Boolean(file) && typeof file.size === 'number' && file.size === 0;
    } catch (error) {
        return false;
    }
}

/**
 * Can the browser read this photo at all? One byte is asked for.
 *
 * - 0 bytes → `empty`
 * - the read is refused (NotReadableError, NotFoundError...) or comes back without the byte → `unreadable`
 * - the read does not finish in time, or the check itself misbehaves → readable: the photo goes on as it always did
 *
 * @param {Blob|File|null} file
 * @param {number} timeoutMs
 * @returns {Promise<{unreadable: null|'empty'|'unreadable', error: null|string}>} never rejects
 */
export async function probePhoto(file, timeoutMs = PROBE_TIMEOUT_MS) {
    const readable = { unreadable: null, error: null };
    let timer = null;

    try {
        if (!file) {
            return readable;
        }

        if (isEmptyPhoto(file)) {
            return { unreadable: 'empty', error: null };
        }

        if (typeof file.slice !== 'function') {
            return readable;
        }

        const firstByte = file.slice(0, 1);

        if (!firstByte || typeof firstByte.arrayBuffer !== 'function') {
            return readable;
        }

        const reading = firstByte.arrayBuffer().then(
            (buffer) => ({ buffer }),
            (error) => ({ error }),
        );
        const timeout = new Promise((resolve) => {
            timer = setTimeout(() => resolve(TIMED_OUT), timeoutMs);
        });

        const outcome = await Promise.race([reading, timeout]);

        if (outcome === TIMED_OUT) {
            return readable;
        }

        if ('error' in outcome) {
            return { unreadable: 'unreadable', error: describeError(outcome.error, [file.name]) };
        }

        // The file says it has bytes and gives none
        if (outcome.buffer && typeof outcome.buffer.byteLength === 'number' && outcome.buffer.byteLength === 0) {
            return { unreadable: 'unreadable', error: 'EmptyRead' };
        }

        return readable;
    } catch (error) {
        return readable;
    } finally {
        if (timer !== null) {
            clearTimeout(timer);
        }
    }
}

/**
 * "TypeError: Failed to fetch", "NotReadableError: The requested file could not be read…" - for the telemetry, so the
 * next case explains itself. No file name, no path, no address ever leaves the browser in it.
 *
 * @param {*} error
 * @param {Array<string|undefined|null>} hide texts to leave out (the photo's file name)
 * @returns {null|string}
 */
export function describeError(error, hide = []) {
    try {
        if (error === null || error === undefined) {
            return null;
        }

        const name = typeof error.name === 'string' ? error.name : '';
        const message = typeof error.message === 'string' ? error.message : (typeof error === 'string' ? error : '');
        let text = [name, message].filter((part) => part !== '').join(': ');

        if (text === '') {
            return null;
        }

        for (const secret of hide) {
            if (typeof secret === 'string' && secret.length > 2) {
                text = text.split(secret).join('[file]');
            }
        }

        text = text
            .replace(/\b(?:blob|file|content|https?):[^\s'"<>)]+/gi, '[url]')
            .replace(/(?:[A-Za-z]:)?[\\/](?:[^\s\\/'"<>:]+[\\/])+[^\s\\/'"<>:]*/g, '[path]')
            .replace(/\s+/g, ' ')
            .trim();

        return text === '' ? null : text.slice(0, ERROR_TEXT_MAX);
    } catch (failure) {
        return null;
    }
}

/**
 * A save never got its answer. Which message does the player get?
 *
 * - `photo`: it died at once, online, with a photo in the request - the browser could not send the photo. The page
 *   offers "Save without the photo".
 * - `either`: not at once, not slow either - both possibilities are named, the same offer.
 * - `network`: everything the old message was written for - no photo in the request, offline, the edge's 502/504 after
 *   a slow upload, a long wait.
 *
 * @param {{status?: number, durationMs?: number|null, online?: boolean, photosInForm?: number, discarded?: boolean}} save
 * @returns {'photo'|'either'|'network'}
 */
export function saveFailureKind(save) {
    try {
        const { status = 0, durationMs = null, online = true, photosInForm = 0, discarded = false } = save || {};

        if (!(photosInForm > 0) || discarded || online === false || status !== 0) {
            return 'network';
        }

        if (typeof durationMs !== 'number' || !Number.isFinite(durationMs) || durationMs < 0) {
            return 'either';
        }

        if (durationMs < FAST_FAILURE_MS) {
            return 'photo';
        }

        return durationMs < SLOW_FAILURE_MS ? 'either' : 'network';
    } catch (error) {
        return 'network';
    }
}

/**
 * The upload start's refusal (PhotoUploadStartController: `{"error": "empty"}`) - read without ever holding the upload
 * up: null when the answer cannot be read in time.
 *
 * @param {Response} response
 * @param {number} timeoutMs
 * @returns {Promise<null|string>} never rejects
 */
export async function refusalOf(response, timeoutMs = PROBE_TIMEOUT_MS) {
    let timer = null;

    try {
        const timeout = new Promise((resolve) => {
            timer = setTimeout(() => resolve(null), timeoutMs);
        });
        const data = await Promise.race([response.json().catch(() => null), timeout]);

        return data && typeof data.error === 'string' ? data.error.slice(0, 30) : null;
    } catch (error) {
        return null;
    } finally {
        if (timer !== null) {
            clearTimeout(timer);
        }
    }
}
