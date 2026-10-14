/**
 * The player card's trigger rules (docs/features/navigation README §4.8, CARD-01a) - pure functions over the event and
 * the link, no DOM writes, so tests/player-card-harness.mjs runs them under node (tests/PlayerCardScriptTest.php).
 *
 * A trigger is a server-rendered link to the player's profile carrying data-player-card (+ aria-haspopup="dialog"); its
 * href stays the profile, so whatever does not open the card navigates there.
 */

/** The player id in the card URL template on <body data-player-card-url> */
export const PLAYER_ID_PLACEHOLDER = '00000000-0000-0000-0000-000000000000';

export const TRIGGER_SELECTOR = 'a[data-player-card]';

/** Open dialogs a trigger may sit in: the card itself, the Menu panel / overlay (they hand the history entry over) */
const LAYER_DIALOGS = '.player-card-layer, #menu-layer';

const UUID = /[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/gi;
const ONE_UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

const INPUTS = ['mouse', 'touch', 'pen'];

/** The context's kind ('puzzle', 'feed', … - CARD-02), 'none' without one */
export function contextKindOf(context) {
    if (typeof context !== 'string' || context === '') {
        return 'none';
    }

    const kind = context.split(':')[0];

    return /^[a-z]+$/.test(kind) ? kind : 'none';
}

/**
 * How the card was asked for: a click without a pointer behind it (Enter on a focused link: detail 0) is the keyboard;
 * else the click's pointerType, else the type of the last pointerdown (Safari's clicks have none), else a mouse
 */
export function inputOf(event, lastPointerType = null) {
    if (event && event.detail === 0) {
        return 'keyboard';
    }

    const type = event && typeof event.pointerType === 'string' ? event.pointerType : '';

    if (INPUTS.includes(type)) {
        return type;
    }

    return INPUTS.includes(lastPointerType) ? lastPointerType : 'mouse';
}

/**
 * The revert rule (CARD-07): "kind:input" pairs whose triggers navigate straight to the profile - from
 * <body data-player-card-direct="…"> (space-separated), a Set, or nothing (nothing reverted)
 */
export function isReverted(direct, kind, input) {
    if (!direct) {
        return false;
    }

    const pair = kind + ':' + input;

    if (typeof direct === 'string') {
        return direct.split(/\s+/).includes(pair);
    }

    return typeof direct.has === 'function' && direct.has(pair);
}

/** The card's player: the data-player-card value when it is an id, else the last id in the href's path */
export function playerIdOf(link) {
    if (!link || typeof link.getAttribute !== 'function') {
        return null;
    }

    const own = String(link.getAttribute('data-player-card') || '').trim().toLowerCase();

    if (ONE_UUID.test(own)) {
        return own;
    }

    const href = link.getAttribute('href');

    if (typeof href !== 'string') {
        return null;
    }

    // The path only: ?return=/en/player-profile/<other> or ?team=<uuid> must not win
    const path = href.split(/[?#]/)[0];
    const ids = path.match(UUID);

    return ids ? ids[ids.length - 1].toLowerCase() : null;
}

/** The card's context: the closest data-player-card-context (a row or the list around it), else null */
export function contextOf(link) {
    if (!link || typeof link.closest !== 'function') {
        return null;
    }

    const container = link.closest('[data-player-card-context]');
    const value = container ? container.getAttribute('data-player-card-context') : null;

    return value ? value : null;
}

/** The card's URL: the template with the player's id, plus ?context= when there is one */
export function cardUrl(template, playerId, context = null) {
    if (typeof template !== 'string' || !template.includes(PLAYER_ID_PLACEHOLDER) || !playerId) {
        return null;
    }

    const url = template.replace(PLAYER_ID_PLACEHOLDER, playerId);

    return context ? url + (url.includes('?') ? '&' : '?') + 'context=' + encodeURIComponent(context) : url;
}

/**
 * Does this click open the card (instead of following the link to the profile)?
 * Only a plain primary click nobody handled yet: Ctrl/Cmd/Shift/Alt and the middle button keep opening the profile in
 * a new tab or window; a link with a target or a download navigates; never inside a modal (no triggers inside modals,
 * README §4.8 - names link to the profile there) nor inside an open dialog other than the card and the Menu; never
 * inside the native app (its own navigation opens the profile); never for a context and input the revert rule sends
 * straight to the profile.
 *
 * env: {nativeApp: bool, direct: string|Set (CARD-07 revert rule), lastPointerType}
 */
export function shouldOpen(event, link, env = {}) {
    if (!event || !link || typeof link.getAttribute !== 'function') {
        return false;
    }

    if (env.nativeApp === true) {
        return false;
    }

    if (event.defaultPrevented || (event.button !== undefined && event.button !== 0)) {
        return false;
    }

    if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
        return false;
    }

    const target = link.getAttribute('target');

    if ((target && target !== '_self') || link.getAttribute('download') !== null) {
        return false;
    }

    if (typeof link.closest === 'function') {
        if (link.closest('.modal')) {
            return false;
        }

        const dialog = link.closest('dialog[open]');

        if (dialog && !(typeof dialog.matches === 'function' && dialog.matches(LAYER_DIALOGS))) {
            return false;
        }
    }

    if (playerIdOf(link) === null) {
        return false;
    }

    return !isReverted(env.direct, contextKindOf(contextOf(link)), inputOf(event, env.lastPointerType || null));
}

/**
 * Was this a trigger opened in a new tab or window instead of the card (CARD-07 `card_bypass`)? A click a plain click
 * of would have opened the card, made with Ctrl/Cmd/Shift/Alt (button 0, the `click` event) or the middle button
 * (button 1, the `auxclick` event) - never one somebody else handled, never inside the native app, never where the
 * revert rule sends that context and input straight to the profile anyway.
 */
export function bypassOf(event, link, env = {}) {
    if (!event || event.defaultPrevented) {
        return false;
    }

    const modifier = Boolean(event.ctrlKey || event.metaKey || event.shiftKey || event.altKey);
    const middle = event.button === 1;

    if (!middle && !(modifier && (event.button === undefined || event.button === 0))) {
        return false;
    }

    // The same click without the modifier, with the primary button: would it have opened the card?
    const plain = {
        button: 0,
        detail: event.detail,
        pointerType: event.pointerType,
        defaultPrevented: false,
        ctrlKey: false,
        metaKey: false,
        shiftKey: false,
        altKey: false,
    };

    return shouldOpen(plain, link, env);
}
