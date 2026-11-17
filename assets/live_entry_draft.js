/**
 * The result being entered survives a reload (competition toolkit C4, docs/features/competitions-management/toolkit/
 * C-judge-page.md §1.7, §1.8): an opened entry, a typed time or pieces count, a value waiting on the confirm view -
 * a tapped "Finished now" included, restored exactly as frozen, never measured again.
 *
 * - sessionStorage (the draft belongs to this tab), key `msp.liveResults.draft.<playerId>`: one draft per player, only
 *   ever restored for the same player and round, within 10 minutes. Never saved by itself - the referee still taps Save.
 * - Cleared on Save, Back and Undo (the page goes back to finding); other players' drafts go when the page starts and
 *   after a sign-out (live_entry_device.js).
 * - The referee's preferences: one localStorage key `msp.liveResults.prefs`, the older `msp.liveResults.keyboard`
 *   taken over on the first read.
 *
 * Pure functions over an injected Storage - tests/live-entry-offline-harness.mjs runs them under node.
 */

import { DRAFT_KEY_PREFIX, samePlayer } from './live_entry_device.js';

export const DRAFT_MAX_AGE_MS = 10 * 60 * 1000;
export const PREFS_KEY = 'msp.liveResults.prefs';
export const LEGACY_KEYBOARD_KEY = 'msp.liveResults.keyboard';

const PREFS_DEFAULTS = { v: 1, keyboard: null, autoOpenTable: true, scanAfterSave: false, myTables: [] };

export function draftKey(playerId) {
    return DRAFT_KEY_PREFIX + String(playerId).toLowerCase();
}

function isResultValue(value) {
    if (value === null) {
        return true;
    }

    if (typeof value !== 'object' || Array.isArray(value)) {
        return false;
    }

    return (Number.isInteger(value.seconds) && value.seconds >= 0)
        || (Number.isInteger(value.piecesPlaced) && value.piecesPlaced >= 0)
        || value.didNotStart === true;
}

/**
 * A draft as stored, checked field by field - null when anything does not fit (a draft of an older page, a hand edit).
 */
export function normaliseDraft(raw) {
    if (raw === null || typeof raw !== 'object') {
        return null;
    }

    const { playerId, roundId, ref, view, mode, typed, pieces, pending, finishedNow, savedAt } = raw;

    if (typeof playerId !== 'string' || typeof roundId !== 'string' || typeof ref !== 'string' || ref === '' || typeof savedAt !== 'number') {
        return null;
    }

    if (view !== 'entry' && view !== 'confirm') {
        return null;
    }

    const draft = {
        v: 1,
        playerId: playerId.toLowerCase(),
        roundId,
        ref,
        view,
        mode: mode === 'pieces' ? 'pieces' : 'time',
        typed: typeof typed === 'string' ? typed.slice(0, 40) : '',
        pieces: typeof pieces === 'string' ? pieces.slice(0, 10) : '',
        pending: null,
        finishedNow: null,
        savedAt,
    };

    if (view === 'confirm') {
        if (pending === null || typeof pending !== 'object' || !('value' in pending) || !isResultValue(pending.value)) {
            return null;
        }

        draft.pending = { value: pending.value === null ? null : { ...pending.value } };

        // A frozen "Finished now": the time it gave and the moment it was tapped (server clock) - both or nothing
        if (finishedNow !== null && finishedNow !== undefined) {
            if (!Number.isInteger(finishedNow.seconds) || typeof finishedNow.tappedAt !== 'number'
                || draft.pending.value === null || draft.pending.value.seconds !== finishedNow.seconds) {
                return null;
            }

            draft.finishedNow = { seconds: finishedNow.seconds, tappedAt: finishedNow.tappedAt };
        }
    }

    return draft;
}

/**
 * Stores the tab's draft for the player (replacing the previous one).
 *
 * @returns {boolean} stored
 */
export function writeDraft(storage, playerId, draft, now) {
    if (typeof playerId !== 'string' || playerId === '') {
        return false;
    }

    const normalised = normaliseDraft({ ...draft, playerId, savedAt: now });

    if (normalised === null) {
        return false;
    }

    try {
        storage.setItem(draftKey(playerId), JSON.stringify(normalised));

        return true;
    } catch (e) {
        return false;
    }
}

/**
 * The player's draft of this round, younger than 10 minutes - else null (and an outdated or broken one goes).
 */
export function readDraft(storage, playerId, roundId, now) {
    if (typeof playerId !== 'string' || playerId === '') {
        return null;
    }

    let draft = null;

    try {
        draft = normaliseDraft(JSON.parse(storage.getItem(draftKey(playerId)) ?? 'null'));
    } catch (e) {
        draft = null;
    }

    if (draft === null || !samePlayer(draft.playerId, playerId)) {
        clearDraft(storage, playerId);

        return null;
    }

    if (now - draft.savedAt > DRAFT_MAX_AGE_MS || draft.savedAt - now > 60000) {
        clearDraft(storage, playerId);

        return null;
    }

    // Another round's draft stays for that round's page (same tab, a round switch and back)
    return draft.roundId === roundId ? draft : null;
}

export function clearDraft(storage, playerId) {
    try {
        storage.removeItem(draftKey(playerId));
    } catch (e) {
        // Nothing stored
    }
}

/**
 * The referee's preferences - defaults where nothing (valid) is stored; the older keyboard key is taken over once.
 */
export function readPrefs(storage) {
    let stored = null;

    try {
        stored = JSON.parse(storage.getItem(PREFS_KEY) ?? 'null');
    } catch (e) {
        stored = null;
    }

    if (stored === null || typeof stored !== 'object' || Array.isArray(stored)) {
        let legacy = null;

        try {
            legacy = storage.getItem(LEGACY_KEYBOARD_KEY);
        } catch (e) {
            legacy = null;
        }

        const prefs = { ...PREFS_DEFAULTS, myTables: [], keyboard: legacy === 'numeric' || legacy === 'text' ? legacy : null };

        if (legacy !== null) {
            try {
                storage.setItem(PREFS_KEY, JSON.stringify(prefs));
                storage.removeItem(LEGACY_KEYBOARD_KEY);
            } catch (e) {
                // Taken over next time
            }
        }

        return prefs;
    }

    return {
        v: 1,
        keyboard: stored.keyboard === 'numeric' || stored.keyboard === 'text' ? stored.keyboard : null,
        autoOpenTable: typeof stored.autoOpenTable === 'boolean' ? stored.autoOpenTable : PREFS_DEFAULTS.autoOpenTable,
        scanAfterSave: typeof stored.scanAfterSave === 'boolean' ? stored.scanAfterSave : PREFS_DEFAULTS.scanAfterSave,
        myTables: Array.isArray(stored.myTables) ? stored.myTables.filter((table) => Number.isInteger(table) && table > 0).slice(0, 200) : [],
    };
}

/**
 * Changes some preferences, keeps the others.
 *
 * @returns {object} the preferences now
 */
export function writePrefs(storage, changes) {
    const prefs = { ...readPrefs(storage), ...changes, v: 1 };

    try {
        storage.setItem(PREFS_KEY, JSON.stringify(prefs));
    } catch (e) {
        // A private window - the defaults next time
    }

    return readPrefs({ getItem: () => JSON.stringify(prefs), setItem: () => {}, removeItem: () => {} });
}
