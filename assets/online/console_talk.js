/**
 * Push-to-talk into the hall of an online round (docs/features/online-competitions/README.md §5.12 "The moderator
 * console", contract phase-1b.md §8: the host joins the hall with `allowSubscribers: 'all'`) - the pure state, no DOM,
 * pinned by tests/online-console-harness.mjs. The console (assets/online/console_video.js) turns it into the
 * microphone's mute / unmute and the on-air indicator.
 *
 * - The microphone is off until asked for (`enable` → `starting`, then `enabled` → `ready`, or `failed`): it is
 *   published muted, never live before somebody talks.
 * - On air (isOnAir()) only while something holds it - the button (pointer) or the Space key; several holders may hold
 *   at once and the last release ends it - or while the latch is on (longer announcements).
 * - `release_all` (the window lost focus, the page went into the background: a key-up would never come) lets go of
 *   every holder, never of the latch - it is switched off on purpose only.
 * - `off` (the hall's connection is gone, the page leaves): everything off.
 */

export const INITIAL_TALK = Object.freeze({ mic: 'off', holders: Object.freeze([]), latched: false, error: null });

export const MIC_ERRORS = ['mic_denied', 'mic_missing', 'mic_failed', 'not_connected'];

/**
 * @param {{mic: 'off'|'starting'|'ready'|'error', holders: string[], latched: boolean, error: string|null}} state
 * @param {{type: string, source?: string, reason?: string}} event
 */
export function talkReducer(state = INITIAL_TALK, event = {}) {
    switch (event?.type) {
        case 'enable':
            return state.mic === 'off' || state.mic === 'error'
                ? { ...INITIAL_TALK, mic: 'starting' }
                : state;

        case 'enabled':
            return state.mic === 'starting' ? { ...state, mic: 'ready', error: null } : state;

        case 'failed':
            return { ...INITIAL_TALK, mic: 'error', error: MIC_ERRORS.includes(event.reason) ? event.reason : 'mic_failed' };

        case 'press':
            if (state.mic !== 'ready' || typeof event.source !== 'string' || event.source === '' || state.holders.includes(event.source)) {
                return state;
            }

            return { ...state, holders: [...state.holders, event.source] };

        case 'release':
            return state.holders.includes(event.source)
                ? { ...state, holders: state.holders.filter((source) => source !== event.source) }
                : state;

        case 'release_all':
            return state.holders.length === 0 ? state : { ...state, holders: [] };

        case 'latch':
            return state.mic === 'ready' ? { ...state, latched: !state.latched } : state;

        case 'off':
            return state.mic === 'off' && state.holders.length === 0 && state.latched === false && state.error === null ? state : { ...INITIAL_TALK };

        default:
            return state;
    }
}

/**
 * The microphone is open to the hall
 */
export function isOnAir(state) {
    return state?.mic === 'ready' && (state.latched === true || (Array.isArray(state.holders) && state.holders.length > 0));
}

const NOT_TALK_INPUTS = ['button', 'checkbox', 'radio', 'submit', 'reset', 'range', 'color', 'file', 'image'];

/**
 * Does this key event hold (or let go of) push-to-talk? The Space key, without a modifier, unless it types into a field
 * - a text input, a text area, a select, an editable element. On a button it talks too (its click is kept from
 * happening by the console).
 *
 * @param {{key?: string, code?: string, altKey?: boolean, ctrlKey?: boolean, metaKey?: boolean, target?: object}} event
 */
export function spaceTalks(event = {}) {
    if (event?.code !== 'Space' && event?.key !== ' ') {
        return false;
    }

    if (event.altKey === true || event.ctrlKey === true || event.metaKey === true) {
        return false;
    }

    const target = event.target ?? null;
    const tag = typeof target?.tagName === 'string' ? target.tagName.toUpperCase() : '';

    if (target?.isContentEditable === true || tag === 'TEXTAREA' || tag === 'SELECT') {
        return false;
    }

    if (tag === 'INPUT') {
        return NOT_TALK_INPUTS.includes(String(target.type ?? 'text').toLowerCase());
    }

    return true;
}

/**
 * How long the microphone has been open, for the on-air indicator: "0:07", "1:05", "12:40"
 */
export function formatOnAir(ms) {
    const seconds = Number.isFinite(ms) && ms > 0 ? Math.floor(ms / 1000) : 0;

    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
}
