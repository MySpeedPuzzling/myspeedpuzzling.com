/**
 * The Help sheet's feedback draft (docs/features/navigation README §4.5, HELP-01b): what a player typed into the
 * feedback box survives closing the sheet - by ×, Esc, Back or a swipe that started in the field - and comes back when
 * it opens again in the same tab. Kept in sessionStorage under one key, dropped once the message was sent (the sheet's
 * sent state, controllers/help_sheet_controller.js). Plain functions over a Storage-like object - no DOM globals - so
 * tests/feedback-draft-harness.mjs runs them under node; storage can be missing or throw (private mode, blocked site
 * data, quota): the draft then lives only as long as the field.
 */

export const FEEDBACK_DRAFT_KEY = 'msp:feedback-draft';

/** window.sessionStorage, or null where reading it throws (blocked storage) */
export function sessionStorageOf(win) {
    try {
        return win && win.sessionStorage ? win.sessionStorage : null;
    } catch (e) {
        return null;
    }
}

/** The kept draft, '' when there is none */
export function readDraft(storage) {
    try {
        const value = storage ? storage.getItem(FEEDBACK_DRAFT_KEY) : null;

        return typeof value === 'string' ? value : '';
    } catch (e) {
        return '';
    }
}

/** Keeps what is in the field; an empty (or blank) field keeps nothing */
export function saveDraft(storage, value) {
    if (!storage) {
        return;
    }

    try {
        if (typeof value !== 'string' || value.trim() === '') {
            storage.removeItem(FEEDBACK_DRAFT_KEY);
        } else {
            storage.setItem(FEEDBACK_DRAFT_KEY, value);
        }
    } catch (e) {
        // Storage blocked or full: the draft lives only as long as the field
    }
}

/** The message was sent: nothing to bring back */
export function clearDraft(storage) {
    saveDraft(storage, '');
}

/**
 * Puts the kept draft back into an empty field (a field the server filled - a form back with an error - keeps its text).
 * Returns whether it did.
 */
export function restoreDraft(field, storage) {
    if (!field || field.value !== '') {
        return false;
    }

    const draft = readDraft(storage);

    if (draft === '') {
        return false;
    }

    field.value = draft;

    return true;
}
