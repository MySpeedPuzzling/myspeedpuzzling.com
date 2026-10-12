// Runs assets/feedback_draft.js (the Help sheet's feedback draft, HELP-01b) through the scenarios
// tests/FeedbackDraftScriptTest.php asks for and prints one JSON object of results. Storage is a plain Map-backed fake
// (or one that throws, like a blocked sessionStorage); a field is {value}. Drafts belong to a player (review F8).

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

const A = '018d0000-0000-0000-0000-0000000000a1';
const B = '018d0000-0000-0000-0000-0000000000b2';
const results = {};

// Typed text is kept under the one key and read back
{
    const s = storage();
    saveDraft(s, 'The chart is upside down', A);
    results.key = FEEDBACK_DRAFT_KEY;
    results.saved = { stored: JSON.parse(s.items.get(FEEDBACK_DRAFT_KEY) ?? 'null'), read: readDraft(s, A) };
}

// Emptying the field (or only spaces) keeps nothing
{
    const s = storage();
    saveDraft(s, 'Something', A);
    saveDraft(s, '   ', A);
    const afterBlank = s.items.has(FEEDBACK_DRAFT_KEY);
    saveDraft(s, 'Again', A);
    saveDraft(s, '', A);
    results.blank = { afterBlank, afterEmpty: s.items.has(FEEDBACK_DRAFT_KEY), read: readDraft(s, A) };
}

// Restore: into an empty field only - a field the server filled (a form back with an error) keeps its text
{
    const s = storage();
    saveDraft(s, 'My draft', A);
    const empty = { value: '' };
    const filled = { value: 'Sent back by the server' };
    results.restore = {
        intoEmpty: restoreDraft(empty, s, A),
        emptyValue: empty.value,
        intoFilled: restoreDraft(filled, s, A),
        filledValue: filled.value,
        noField: restoreDraft(null, s, A),
        nothingKept: restoreDraft({ value: '' }, storage(), A),
    };
}

// Per player (review F8): player A logs out, player B signs in in the same tab - B never gets A's draft, A still does;
// no player (a guest) keeps nothing; an old plain-text value or broken JSON is nobody's
{
    const s = storage();
    saveDraft(s, 'Unsent words of A', A);
    const forB = { value: '' };
    const forA = { value: '' };
    const restoredForB = restoreDraft(forB, s, B);
    const restoredForA = restoreDraft(forA, s, A);
    const guest = storage();
    saveDraft(guest, 'A guest typed this', '');
    saveDraft(guest, 'A guest typed this', null);
    const legacy = storage();
    legacy.setItem(FEEDBACK_DRAFT_KEY, 'Plain text from before');
    const broken = storage();
    broken.setItem(FEEDBACK_DRAFT_KEY, '{"player":');
    // B typing replaces A's draft: A no longer gets it either
    saveDraft(s, 'Words of B', B);
    results.perPlayer = {
        restoredForB,
        valueForB: forB.value,
        restoredForA,
        valueForA: forA.value,
        readByNobody: readDraft(s, ''),
        guestKept: guest.items.has(FEEDBACK_DRAFT_KEY),
        legacy: readDraft(legacy, A),
        broken: readDraft(broken, A),
        afterB: { a: readDraft(s, A), b: readDraft(s, B) },
    };
}

// The sent state clears it: nothing comes back on the next open
{
    const s = storage();
    saveDraft(s, 'Sent message', A);
    clearDraft(s);
    const field = { value: '' };
    results.cleared = { stored: s.items.has(FEEDBACK_DRAFT_KEY), restored: restoreDraft(field, s, A), value: field.value };
}

// Blocked or missing storage: nothing throws, the draft lives only in the field
{
    const t = throwingStorage();
    let threw = false;
    let read = null;
    let restored = null;

    try {
        saveDraft(t, 'x', A);
        clearDraft(t);
        read = readDraft(t, A);
        restored = restoreDraft({ value: '' }, t, A);
        saveDraft(null, 'x', A);
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
        nullRead: readDraft(null, A),
        windowWithout: sessionStorageOf({}),
        windowBlocked: sessionStorageOf(blockedWindow),
        noWindow: sessionStorageOf(undefined),
        windowWith: sessionStorageOf({ sessionStorage: storage() }) !== null,
    };
}

process.stdout.write(JSON.stringify(results));
