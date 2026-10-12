// Runs assets/feedback_draft.js (the Help sheet's feedback draft, HELP-01b) through the scenarios
// tests/FeedbackDraftScriptTest.php asks for and prints one JSON object of results. Storage is a plain Map-backed fake
// (or one that throws, like a blocked sessionStorage); a field is {value}.

import {
    clearDraft,
    FEEDBACK_DRAFT_KEY,
    readDraft,
    restoreDraft,
    saveDraft,
    sessionStorageOf,
} from '../assets/feedback_draft.js';

function storage() {
    const items = new Map();

    return {
        items,
        getItem: (key) => (items.has(key) ? items.get(key) : null),
        setItem: (key, value) => items.set(key, String(value)),
        removeItem: (key) => items.delete(key),
    };
}

function throwingStorage() {
    const fail = () => {
        throw new Error('SecurityError: storage blocked');
    };

    return { getItem: fail, setItem: fail, removeItem: fail };
}

const results = {};

// Typed text is kept under the one key and read back
{
    const s = storage();
    saveDraft(s, 'The chart is upside down');
    results.key = FEEDBACK_DRAFT_KEY;
    results.saved = { stored: s.items.get(FEEDBACK_DRAFT_KEY) ?? null, read: readDraft(s) };
}

// Emptying the field (or only spaces) keeps nothing
{
    const s = storage();
    saveDraft(s, 'Something');
    saveDraft(s, '   ');
    const afterBlank = s.items.has(FEEDBACK_DRAFT_KEY);
    saveDraft(s, 'Again');
    saveDraft(s, '');
    results.blank = { afterBlank, afterEmpty: s.items.has(FEEDBACK_DRAFT_KEY), read: readDraft(s) };
}

// Restore: into an empty field only - a field the server filled (a form back with an error) keeps its text
{
    const s = storage();
    saveDraft(s, 'My draft');
    const empty = { value: '' };
    const filled = { value: 'Sent back by the server' };
    results.restore = {
        intoEmpty: restoreDraft(empty, s),
        emptyValue: empty.value,
        intoFilled: restoreDraft(filled, s),
        filledValue: filled.value,
        noField: restoreDraft(null, s),
        nothingKept: restoreDraft({ value: '' }, storage()),
    };
}

// The sent state clears it: nothing comes back on the next open
{
    const s = storage();
    saveDraft(s, 'Sent message');
    clearDraft(s);
    const field = { value: '' };
    results.cleared = { stored: s.items.has(FEEDBACK_DRAFT_KEY), restored: restoreDraft(field, s), value: field.value };
}

// Blocked or missing storage: nothing throws, the draft lives only in the field
{
    const t = throwingStorage();
    let threw = false;
    let read = null;
    let restored = null;

    try {
        saveDraft(t, 'x');
        clearDraft(t);
        read = readDraft(t);
        restored = restoreDraft({ value: '' }, t);
        saveDraft(null, 'x');
        clearDraft(null);
    } catch (e) {
        threw = true;
    }

    const blockedWindow = {};
    Object.defineProperty(blockedWindow, 'sessionStorage', {
        get() {
            throw new Error('SecurityError');
        },
    });

    results.blocked = {
        threw,
        read,
        restored,
        nullRead: readDraft(null),
        windowWithout: sessionStorageOf({}),
        windowBlocked: sessionStorageOf(blockedWindow),
        noWindow: sessionStorageOf(undefined),
        windowWith: sessionStorageOf({ sessionStorage: storage() }) !== null,
    };
}

process.stdout.write(JSON.stringify(results));
