/**
 * Who the live result entry's saved copies on this device belong to (competition toolkit C4,
 * docs/features/competitions-management/toolkit/C-judge-page.md §1.5, §1.7, R §2.7).
 *
 * The live entry page keeps the last known state of each round per signed-in player (live_entry_snapshot.js, IndexedDB)
 * and a result being typed per tab (live_entry_draft.js, sessionStorage). Both belong to the player the device cookie
 * `msp_live_player` names (src/Value/LiveEntryDeviceCookie.php - set by the live page for organisers and referees,
 * cleared on sign-out and when another player signs in). When the cookie no longer names the player whose copies are
 * on the device, they go: the live page wipes every other player's copies when it opens, and every other page of the
 * site (app.js → leftLiveEntryCleanup()) wipes them right after a sign-out - a shared phone keeps nobody's rounds.
 *
 * Unsent results (the outbox, official_results_outbox.js) are never touched here: they are the referee's own work and
 * are sent only by the same player.
 *
 * Small on purpose - the site's `app` bundle imports it.
 */

export const DEVICE_COOKIE = 'msp_live_player';
export const SNAPSHOT_DATABASE = 'msp-live-entry';
export const DRAFT_KEY_PREFIX = 'msp.liveResults.draft.';
// localStorage: the player whose snapshots this device holds (set by the live page) - other pages compare it to the cookie
export const DEVICE_MARKER_KEY = 'msp.liveEntry.player';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/**
 * The player the device cookie names (lower case), null without one or with a value that is no id.
 */
export function devicePlayerOf(cookieHeader) {
    for (const part of String(cookieHeader ?? '').split(';')) {
        const separator = part.indexOf('=');

        if (separator === -1 || part.slice(0, separator).trim() !== DEVICE_COOKIE) {
            continue;
        }

        let value = part.slice(separator + 1).trim();

        try {
            value = decodeURIComponent(value);
        } catch (e) {
            return null;
        }

        return UUID.test(value) ? value.toLowerCase() : null;
    }

    return null;
}

export function samePlayer(a, b) {
    return typeof a === 'string' && typeof b === 'string' && a !== '' && a.toLowerCase() === b.toLowerCase();
}

/**
 * Removes the drafts of every player but `keepPlayerId` (null: every draft) from a Storage (sessionStorage).
 *
 * @returns {number} how many went
 */
export function forgetDraftsExcept(storage, keepPlayerId = null) {
    let removed = 0;

    try {
        const keys = [];

        for (let i = 0; i < storage.length; i += 1) {
            const key = storage.key(i);

            if (typeof key === 'string' && key.startsWith(DRAFT_KEY_PREFIX)) {
                keys.push(key);
            }
        }

        for (const key of keys) {
            if (keepPlayerId === null || !samePlayer(key.slice(DRAFT_KEY_PREFIX.length), keepPlayerId)) {
                storage.removeItem(key);
                removed += 1;
            }
        }
    } catch (e) {
        // No storage: nothing kept there either
    }

    return removed;
}

/**
 * Any page of the site: the device holds a player's live entry copies and the cookie no longer names that player
 * (signed out, another player signed in) - the copies go. A device that never opened the live entry pays one
 * localStorage read.
 *
 * @returns {boolean} whether anything was wiped
 */
export function leftLiveEntryCleanup({
    cookie = globalThis.document?.cookie,
    local = globalThis.localStorage,
    session = globalThis.sessionStorage,
    indexedDB = globalThis.indexedDB,
} = {}) {
    let marker = null;

    try {
        marker = local?.getItem(DEVICE_MARKER_KEY) ?? null;
    } catch (e) {
        return false;
    }

    if (marker === null || samePlayer(devicePlayerOf(cookie), marker)) {
        return false;
    }

    try {
        // Waits for a live page still open in another tab - it closes its connection on `versionchange`
        indexedDB?.deleteDatabase(SNAPSHOT_DATABASE);
    } catch (e) {
        // Nothing stored there
    }

    if (session) {
        forgetDraftsExcept(session, null);
    }

    try {
        local.removeItem(DEVICE_MARKER_KEY);
    } catch (e) {
        // Asked again on the next page
    }

    return true;
}
