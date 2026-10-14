// Runs assets/haptics.js (docs/features/navigation README §4.13 G10, requirement GEST-10) through the cases
// tests/HapticsScriptTest.php asks for and prints one JSON object {case: answer}.

import {
    backend,
    browserEnv,
    haptic,
    install,
    ON_SUCCESS_ATTRIBUTE,
    PATTERNS,
    patternOf,
    readSetting,
    SETTING_KEY,
    SETTING_OFF,
    SETTING_ON,
    shouldPlay,
    signal,
    submitHaptic,
    SWITCH_SELECTOR,
    writeSetting,
} from '../assets/haptics.js';

function run(name, fn) {
    try {
        return [name, fn()];
    } catch (error) {
        return [name, { threw: String(error && error.stack ? error.stack : error) }];
    }
}

/** An env where every rule holds, recording what reaches the device */
function playing(overrides = {}) {
    const played = [];

    return {
        played,
        env: { os: 'android', native: false, setting: SETTING_ON, visibility: 'visible', hasBeenActive: true, vibrate: (pattern) => played.push(pattern), ...overrides },
    };
}

/** A fake <html> with the platform flags (assets/platform.js reads getAttribute) */
function root(attributes) {
    return { getAttribute: (name) => (name in attributes ? attributes[name] : null), classList: { contains: () => false } };
}

// `throws`: every call throws (site data blocked); `refusesWrites`: reads work, writes throw (a full storage)
function storage(values, { throws = false, refusesWrites = false } = {}) {
    return {
        getItem: (key) => {
            if (throws) {
                throw new Error('SecurityError');
            }

            return key in values ? values[key] : null;
        },
        setItem: (key, value) => {
            if (throws || refusesWrites) {
                throw new Error('QuotaExceededError');
            }

            values[key] = value;
        },
    };
}

/** The decision matrix: os × native × setting × visibility × activation × API, the combinations that play */
function matrix() {
    const plays = [];
    let total = 0;

    for (const os of ['android', 'ios', 'other']) {
        for (const native of [false, true]) {
            for (const setting of [SETTING_ON, SETTING_OFF]) {
                for (const visibility of ['visible', 'hidden']) {
                    for (const hasBeenActive of [true, false, undefined]) {
                        for (const api of [true, false]) {
                            total++;
                            const played = [];
                            const env = { os, native, setting, visibility, hasBeenActive, vibrate: api ? (pattern) => played.push(pattern) : null };
                            const answer = haptic('confirm', env);

                            if (answer !== shouldPlay(env) || answer !== (played.length === 1)) {
                                throw new Error('haptic() and shouldPlay() disagree: ' + JSON.stringify({ os, native, setting, visibility, hasBeenActive, api }));
                            }

                            if (answer) {
                                plays.push({ os, native, setting, visibility, hasBeenActive: hasBeenActive === undefined ? 'unknown' : hasBeenActive, api });
                            }
                        }
                    }
                }
            }
        }
    }

    return { total, plays };
}

/** install() on a fake document: the listeners it registers, fed with events */
function installed() {
    const listeners = {};
    const played = [];
    const doc = { addEventListener: (type, listener) => (listeners[type] = listeners[type] || []).push(listener) };

    install({ document: doc, play: (name) => played.push(name) });
    // A second install is a no-op (app.js runs once; a hot reload must not double every haptic)
    install({ document: doc, play: (name) => played.push('second:' + name) });

    const fire = (type, event) => (listeners[type] || []).forEach((listener) => listener(event));
    const checkbox = (isSwitch, checked) => ({ checked, matches: (selector) => selector === SWITCH_SELECTOR && isSwitch });
    const form = (attribute) => ({ getAttribute: (name) => (name === ON_SUCCESS_ATTRIBUTE ? attribute : null) });

    fire('change', { isTrusted: true, target: checkbox(true, true) });
    fire('change', { isTrusted: true, target: checkbox(true, false) });
    fire('change', { isTrusted: false, target: checkbox(true, true) });
    fire('change', { isTrusted: true, target: checkbox(false, true) });
    fire('change', { isTrusted: true, target: { checked: true } });
    fire('turbo:submit-end', { target: form(null), detail: { success: false, fetchResponse: { statusCode: 422 } } });
    fire('turbo:submit-end', { target: form('confirm'), detail: { success: true, fetchResponse: { statusCode: 200 } } });
    fire('turbo:submit-end', { target: form(null), detail: { success: true, fetchResponse: { statusCode: 200 } } });
    fire('turbo:submit-end', { target: form('confirm'), detail: { success: false, fetchResponse: { statusCode: 500 } } });
    fire('turbo:submit-end', { target: form('confirm'), detail: { success: false, error: new Error('offline') } });

    return { listeners: Object.fromEntries(Object.entries(listeners).map(([type, list]) => [type, list.length])), played };
}

const results = Object.fromEntries([
    run('constants', () => ({ PATTERNS, SETTING_KEY, SETTING_ON, SETTING_OFF, ON_SUCCESS_ATTRIBUTE, SWITCH_SELECTOR })),

    run('matrix', matrix),

    // The pattern table: what reaches the device for each name
    run('patterns played', () => Object.fromEntries(Object.keys(PATTERNS).map((name) => {
        const { env, played } = playing();

        return [name, { returned: haptic(name, env), played }];
    }))),
    run('an unknown name plays nothing', () => {
        const { env, played } = playing();

        return { returned: haptic('celebrate', env), played, patternOf: patternOf('celebrate') };
    }),
    run('the reject pattern handed over is a copy', () => {
        const { env, played } = playing();
        haptic('reject', env);
        played[0].push(999);

        return PATTERNS.reject;
    }),

    // Never throws
    run('a vibrate that throws', () => haptic('confirm', { ...playing().env, vibrate: () => { throw new Error('NotAllowedError'); } })),
    run('no env at all (node: no navigator, no document)', () => haptic('confirm')),
    run('a junk env', () => [haptic('confirm', null), haptic('confirm', {}), shouldPlay(undefined)]),
    run('backend', () => ({
        web: backend(playing().env),
        native: backend(playing({ native: true }).env),
        noApi: backend(playing({ vibrate: null }).env),
        none: backend(null),
    })),

    // The device setting (localStorage msp:haptics)
    run('readSetting', () => ({
        missing: readSetting(storage({})),
        off: readSetting(storage({ [SETTING_KEY]: 'off' })),
        on: readSetting(storage({ [SETTING_KEY]: 'on' })),
        junk: readSetting(storage({ [SETTING_KEY]: 'nope' })),
        throwing: readSetting(storage({}, { throws: true })),
        noStorage: readSetting(null),
    })),
    run('writeSetting', () => {
        const values = {};
        const answer = {
            off: [writeSetting(false, storage(values)), values[SETTING_KEY]],
            on: [writeSetting(true, storage(values)), values[SETTING_KEY]],
            throwing: writeSetting(false, storage({}, { throws: true })),
            noStorage: writeSetting(false, null),
        };

        // A write kept again: the storage answers again, the page's own setting is forgotten
        answer.keptAgain = [writeSetting(true, storage(values)), values[SETTING_KEY], readSetting(storage({}))];

        return answer;
    }),

    // Platform feel review F5: a storage that refuses still lets the switch turn haptics off - for this page (in memory)
    run('a refusing storage', () => {
        const played = [];
        const nav = { vibrate: (pattern) => played.push(pattern), userActivation: { hasBeenActive: true } };
        const blocked = storage({}, { throws: true });
        const envWith = (store) => browserEnv({ root: root({ 'data-os': 'android' }), nav, storage: store, doc: { visibilityState: 'visible' } });
        const answer = {};

        // Site data blocked (every call throws): on by default, off once the switch is turned off, on again
        answer.blocked = {
            before: haptic('confirm', envWith(blocked)),
            turnedOff: writeSetting(false, blocked),
            read: [readSetting(blocked), readSetting(null), readSetting()],
            afterOff: haptic('confirm', envWith(blocked)),
            turnedOn: writeSetting(true, blocked),
            afterOn: haptic('confirm', envWith(blocked)),
        };

        // No storage at all (the localStorage getter throws: globalStorage() is null)
        answer.none = { turnedOff: writeSetting(false, null), afterOff: haptic('tick', envWith(null)) };

        // Reads work, writes are refused (a full storage holding "on"): the switch's "off" wins over the stale value
        const full = storage({ [SETTING_KEY]: SETTING_ON }, { refusesWrites: true });
        answer.full = { turnedOff: writeSetting(false, full), read: readSetting(full), afterOff: haptic('confirm', envWith(full)) };

        // A write kept again: the storage decides again
        const values = { [SETTING_KEY]: SETTING_OFF };
        answer.keptAgain = { turnedOn: writeSetting(true, storage(values)), stored: values[SETTING_KEY], read: readSetting(blocked), plays: haptic('confirm', envWith(blocked)) };
        answer.played = played;

        return answer;
    }),

    // browserEnv() reads the platform flags, the setting, visibility and activation; never throws
    run('browserEnv', () => {
        const played = [];
        const nav = { vibrate: (pattern) => played.push(pattern), userActivation: { hasBeenActive: true } };
        const env = browserEnv({ root: root({ 'data-os': 'android' }), nav, storage: storage({}), doc: { visibilityState: 'visible' } });
        const returned = haptic('tick', env);

        return {
            env: { ...env, vibrate: typeof env.vibrate },
            returned,
            played,
            native: browserEnv({ root: root({ 'data-os': 'android', 'data-native-app': 'android' }), nav, storage: storage({}), doc: { visibilityState: 'visible' } }).native,
            ios: browserEnv({ root: root({ 'data-os': 'ios' }), nav: {}, storage: storage({}), doc: { visibilityState: 'visible' } }).os,
            hidden: browserEnv({ root: root({ 'data-os': 'android' }), nav, storage: storage({}), doc: { visibilityState: 'hidden' } }).visibility,
            notActive: browserEnv({ root: root({ 'data-os': 'android' }), nav: { ...nav, userActivation: { hasBeenActive: false } }, storage: storage({}), doc: null }).hasBeenActive,
            noActivationApi: browserEnv({ root: root({ 'data-os': 'android' }), nav: { vibrate: () => true }, storage: storage({}), doc: null }).hasBeenActive === undefined,
            settingOff: browserEnv({ root: root({ 'data-os': 'android' }), nav, storage: storage({ [SETTING_KEY]: 'off' }), doc: null }).setting,
            throwingStorage: browserEnv({ root: root({ 'data-os': 'android' }), nav, storage: storage({}, { throws: true }), doc: null }).setting,
            noVibrate: browserEnv({ root: root({ 'data-os': 'android' }), nav: {}, storage: null, doc: null }).vibrate,
        };
    }),
    run('the vibrate call keeps navigator as its receiver', () => {
        const nav = { calls: [], vibrate(pattern) { this.calls.push(pattern); return true; }, userActivation: { hasBeenActive: true } };
        const returned = haptic('longPress', browserEnv({ root: root({ 'data-os': 'android' }), nav, storage: storage({}), doc: { visibilityState: 'visible' } }));

        return { returned, calls: nav.calls };
    }),

    // signal(): the online round's alerts - their own patterns, wherever the web's API exists, whatever the haptic rules
    // say; through the one backend, so nothing inside the app (Platform feel review Q1, README §4.14 (c))
    run('signal', () => {
        const played = [];
        const record = (pattern) => played.push(pattern);
        const nav = { vibrate: record, userActivation: { hasBeenActive: true } };

        return {
            iosDesktopSettingOffHiddenInactive: [
                signal([250], { os: 'ios', native: false, setting: SETTING_OFF, visibility: 'hidden', hasBeenActive: false, vibrate: record }),
                signal([400, 150, 400, 150, 700], { os: 'other', native: false, setting: SETTING_OFF, visibility: 'visible', hasBeenActive: false, vibrate: record }),
            ],
            insideTheApp: [
                signal([250], { os: 'android', native: true, setting: SETTING_ON, visibility: 'visible', hasBeenActive: true, vibrate: record }),
                signal([400, 150, 400, 150, 700], { os: 'ios', native: true, setting: SETTING_OFF, visibility: 'hidden', hasBeenActive: false, vibrate: record }),
                signal([250], browserEnv({ root: root({ 'data-os': 'android', 'data-native-app': 'android' }), nav, storage: storage({}), doc: { visibilityState: 'visible' } })),
            ],
            browserTab: signal([700], browserEnv({ root: root({ 'data-os': 'android' }), nav, storage: storage({ [SETTING_KEY]: SETTING_OFF }), doc: { visibilityState: 'hidden' } })),
            played,
            noApi: signal([250], { vibrate: null }),
            throwing: signal([250], { vibrate: () => { throw new Error('nope'); } }),
            noEnv: signal([250]),
        };
    }),

    // The submit rule: 422 refuses; a success plays the form's own name, if it is one
    run('submitHaptic', () => ({
        refused: submitHaptic({ statusCode: 422, success: false, onSuccess: 'confirm' }),
        refusedWithoutName: submitHaptic({ statusCode: 422, success: false, onSuccess: null }),
        saved: submitHaptic({ statusCode: 200, success: true, onSuccess: 'confirm' }),
        savedNoName: submitHaptic({ statusCode: 200, success: true, onSuccess: null }),
        savedJunkName: submitHaptic({ statusCode: 200, success: true, onSuccess: 'fireworks' }),
        serverError: submitHaptic({ statusCode: 500, success: false, onSuccess: 'confirm' }),
        networkError: submitHaptic({ statusCode: null, success: false, onSuccess: 'confirm' }),
    })),

    run('install', installed),
]);

process.stdout.write(JSON.stringify(results));
