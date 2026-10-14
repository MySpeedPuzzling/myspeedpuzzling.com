import { isNativeApp, os } from './platform.js';

// Haptics (docs/features/navigation README §4.13 G10, §4.14 (c); requirement GEST-10). The one caller of
// navigator.vibrate in assets/ (tests/PlatformFlagReadersTest.php greps for it).
//
// haptic(name) plays a named pattern only when every rule holds: the device is an Android phone (os() of
// assets/platform.js, never the iOS or desktop browser - iOS gets its own haptic from real `switch` checkboxes, nothing
// else), outside the Hotwire Native app (its backend is none until a bridge component exists), the device's Vibration
// setting (Settings › Preferences, localStorage msp:haptics) is not off, the page is visible, the user has interacted
// with it and the browser has the API. It never throws. Names follow the Web Haptics proposal; patterns are Android's
// own fallback waveforms, one constant per name, never more than 40 ms for a single pulse (README §4.13 G10).
//
// Never on taps of tabs, bar buttons, Menu rows or links, navigation, Back, opening or closing a sheet, panel or dialog,
// scrolling, refresh completion, stopwatch milestones or anything without a preceding user action - the callers below
// are the whole list: scanner reads, live results saves and name tags, the holo medal's turn, the installed app's pull
// threshold, the stopwatch's start / resume / finish, a time saved, a refused form, a switch turned on, the long-press
// label of a cut tab.
//
// signal(pattern) is not a haptic: the online round's alerts (GO, time's up, a detected finish - assets/online/beeps.js)
// keep their own long patterns and the rule they always had (the browser has the API), so a participant feels them
// whatever the device, the Vibration setting or the haptic rules say. It goes through the same backend in one place.
//
// Pure: no DOM access when imported; the browser is read on every call (browserEnv()), or the caller passes an env.

export const PATTERNS = Object.freeze({
    confirm: 20,
    reject: Object.freeze([30, 100, 30]),
    tick: 10,
    toggleOn: 10,
    // Named for a later native bridge (UIKit / performHapticFeedback): nothing on the web
    toggleOff: null,
    longPress: 30,
});

/** The per-device Vibration setting (Settings › Preferences): 'off' silences haptic(), anything else is on */
export const SETTING_KEY = 'msp:haptics';
export const SETTING_ON = 'on';
export const SETTING_OFF = 'off';

/** The attribute a form names the haptic of its successful submit with (the add / edit form: "time saved") */
export const ON_SUCCESS_ATTRIBUTE = 'data-haptic-on-success';

/** A checkbox the system treats as a switch (README §4.13 G10: real iOS switches; Android plays toggleOn) */
export const SWITCH_SELECTOR = 'input[type="checkbox"][switch]';

function globalNavigator() {
    return typeof navigator !== 'undefined' ? navigator : null;
}

function globalStorage() {
    try {
        return typeof localStorage !== 'undefined' ? localStorage : null;
    } catch (e) {
        return null;
    }
}

/** The stored setting: 'on' | 'off' (missing, unreadable or junk = on) */
export function readSetting(storage = globalStorage()) {
    try {
        return storage && storage.getItem(SETTING_KEY) === SETTING_OFF ? SETTING_OFF : SETTING_ON;
    } catch (e) {
        return SETTING_ON;
    }
}

/** Stores the setting; false when the storage refused (a private window) - the switch then lasts for the page only */
export function writeSetting(on, storage = globalStorage()) {
    try {
        if (!storage) {
            return false;
        }

        storage.setItem(SETTING_KEY, on ? SETTING_ON : SETTING_OFF);

        return true;
    } catch (e) {
        return false;
    }
}

/**
 * What haptic() decides on. Every piece may be injected (node tests, the native step-aside harness): `root` is the
 * <html> the platform flags are read from.
 */
export function browserEnv({ root, nav = globalNavigator(), storage = globalStorage(), doc } = {}) {
    const page = doc === undefined ? (typeof document !== 'undefined' ? document : null) : doc;
    let vibrate = null;

    try {
        if (nav && typeof nav.vibrate === 'function') {
            vibrate = (pattern) => nav.vibrate(pattern);
        }
    } catch (e) {
        vibrate = null;
    }

    let hasBeenActive;

    try {
        hasBeenActive = nav && nav.userActivation ? nav.userActivation.hasBeenActive : undefined;
    } catch (e) {
        hasBeenActive = undefined;
    }

    return {
        os: os(root),
        native: isNativeApp(root),
        setting: readSetting(storage),
        visibility: page && typeof page.visibilityState === 'string' ? page.visibilityState : 'visible',
        hasBeenActive,
        vibrate,
    };
}

/** The backend, chosen in one place: the web's vibration API (Android browsers), none inside the app (§4.14 (c)) */
export function backend(env) {
    if (!env || env.native) {
        return 'none';
    }

    return typeof env.vibrate === 'function' ? 'web' : 'none';
}

/** Does a haptic play in this environment? (the rules of README §4.13 G10, in its order) */
export function shouldPlay(env) {
    return Boolean(env)
        && env.os === 'android'
        && !env.native
        && env.setting !== SETTING_OFF
        && env.visibility === 'visible'
        && env.hasBeenActive !== false
        && backend(env) === 'web';
}

/** The pattern of a name, null for a silent or unknown one */
export function patternOf(name) {
    return Object.prototype.hasOwnProperty.call(PATTERNS, name) ? PATTERNS[name] : null;
}

/**
 * Plays the named haptic when the rules allow it. Returns whether it was handed to the device; never throws.
 *
 * @param {'confirm'|'reject'|'tick'|'toggleOn'|'toggleOff'|'longPress'} name
 */
export function haptic(name, env) {
    try {
        const pattern = patternOf(name);

        if (pattern === null) {
            return false;
        }

        const current = env === undefined ? browserEnv() : env;

        if (!shouldPlay(current)) {
            return false;
        }

        current.vibrate(Array.isArray(pattern) ? [...pattern] : pattern);

        return true;
    } catch (e) {
        return false;
    }
}

/**
 * The online round's alerts (assets/online/beeps.js): its own patterns, played wherever the browser has the API - as
 * before this module existed. Never throws; returns whether the pattern was handed to the device.
 *
 * @param {number|number[]} pattern
 */
export function signal(pattern, env) {
    try {
        const current = env === undefined ? browserEnv() : env;

        if (typeof current.vibrate !== 'function') {
            return false;
        }

        current.vibrate(pattern);

        return true;
    } catch (e) {
        return false;
    }
}

/** The haptic of a finished Turbo form submission: 'reject' for a refusal (422), the form's own name on success */
export function submitHaptic({ statusCode, success, onSuccess }) {
    if (statusCode === 422) {
        return 'reject';
    }

    if (success && typeof onSuccess === 'string' && patternOf(onSuccess) !== null) {
        return onSuccess;
    }

    return null;
}

/**
 * The page-wide haptics (app.js): a real switch turned on or off by the user (toggleOn / toggleOff), a refused Turbo
 * form (422 → reject - the first-try and duplicate refusals included) and a form's successful submit naming its haptic
 * (data-haptic-on-success="confirm": the add and edit forms - "time saved", decision 61, independent of where the
 * redirect lands and never again on a reload). Live components' 422s are no Turbo form submissions.
 */
export function install({ document: doc = typeof document !== 'undefined' ? document : null, play = haptic } = {}) {
    if (!doc || doc.__mspHapticsInstalled) {
        return;
    }

    doc.__mspHapticsInstalled = true;

    doc.addEventListener('change', (event) => {
        const target = event.target;

        // A user's toggle only: a script setting .checked fires no change, one dispatching it is not trusted
        if (!event.isTrusted || !target || typeof target.matches !== 'function' || !target.matches(SWITCH_SELECTOR)) {
            return;
        }

        play(target.checked ? 'toggleOn' : 'toggleOff');
    });

    doc.addEventListener('turbo:submit-end', (event) => {
        const detail = event.detail || {};
        const form = event.target;
        const name = submitHaptic({
            statusCode: detail.fetchResponse ? detail.fetchResponse.statusCode : null,
            success: detail.success === true,
            onSuccess: form && typeof form.getAttribute === 'function' ? form.getAttribute(ON_SUCCESS_ATTRIBUTE) : null,
        });

        if (name !== null) {
            play(name);
        }
    });
}
