// The "Get ready" checklist's remembered collapse (W2 of the event creation wizard, templates/event_wizard/_get_ready.html.twig):
// one key per event in the viewer's localStorage. Storage can be missing or throw (a private window, blocked site data) -
// then nothing is remembered and the checklist simply starts open. Pure: get_ready_controller.js passes the storage in;
// tests/get-ready-collapse-harness.mjs runs it under node.

export const STORAGE_PREFIX = 'msp-get-ready-collapsed:';

export function isCollapsed(storage, competitionId) {
    try {
        return storage !== null && storage !== undefined && storage.getItem(STORAGE_PREFIX + competitionId) === '1';
    } catch {
        return false;
    }
}

export function rememberCollapsed(storage, competitionId, collapsed) {
    try {
        if (storage === null || storage === undefined) {
            return;
        }

        if (collapsed) {
            storage.setItem(STORAGE_PREFIX + competitionId, '1');
        } else {
            storage.removeItem(STORAGE_PREFIX + competitionId);
        }
    } catch {
        // Not remembered - the next visit starts open
    }
}

// Whether the checklist starts open: always right after Publish ("Published - here's what's left"), else unless the
// viewer collapsed it before
export function startsOpen(storage, competitionId, forceOpen) {
    return forceOpen === true || isCollapsed(storage, competitionId) === false;
}
