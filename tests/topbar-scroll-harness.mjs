// Runs assets/topbar_scroll.js (the phone top bar's hide on scroll, NAV-03) and the installed-app head script
// assets/head/standalone.js through the cases tests/TopbarScrollScriptTest.php asks for; prints one JSON object.

import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import {
    clampScroll,
    HIDE_AFTER_PX,
    nextTopbarState,
    resetTopbar,
    PHONE_TOPBAR_PX,
    SHORT_VIEWPORT_PX,
    SHOW_AFTER_PX,
    STICKY_FOLLOWERS,
    stuckAt,
    topbarMayHide,
    transformY,
    translateY,
} from '../assets/topbar_scroll.js';
import { focusMovedByTab, focusReturnedWithWindow, installFocusOrigin } from '../assets/navigation/focus_origin.js';

/** Scrolls through the positions, returns whether the bar is hidden after each */
function scroll(positions, mayHide = true, max = Infinity) {
    let state = null;

    return positions.map((y) => {
        state = nextTopbarState(state, y, mayHide, PHONE_TOPBAR_PX, max);

        return state.hidden;
    });
}

/** A fake <html>: the attributes resetTopbar() leaves */
function reset(attributes, options) {
    const root = {
        attributes: { ...attributes },
        setAttribute(name, value) { this.attributes[name] = String(value); },
        removeAttribute(name) { delete this.attributes[name]; },
    };
    resetTopbar(root, options);

    return root.attributes;
}

const phone = { phone: true, viewportHeight: 800, searchOpen: false, menuOpen: false, popupOpen: false, focusInside: false };

/** Runs the head script on a fake window; returns the classes it put on <html> (null when it threw) */
function standalone({ displayMode = false, iosStandalone = false, webdriver = false, flag = null, nativeApp = false, matchMediaThrows = false, storageThrows = false } = {}, file = '../assets/head/standalone.js') {
    const classes = [];
    const window = {
        matchMedia: (query) => {
            if (matchMediaThrows) {
                throw new Error('no matchMedia');
            }

            return { matches: displayMode && query === '(display-mode: standalone)' };
        },
        navigator: { standalone: iosStandalone, webdriver },
        localStorage: {
            getItem: (key) => {
                if (storageThrows) {
                    throw new Error('blocked');
                }

                return key === 'msp:test-standalone' ? flag : null;
            },
        },
        document: {
            documentElement: {
                hasAttribute: (name) => nativeApp && name === 'data-native-app',
                classList: { add: (name) => classes.push(name) },
            },
        },
    };

    try {
        vm.runInNewContext(readFileSync(new URL(file, import.meta.url), 'utf8'), { window });
    } catch (e) {
        return null;
    }

    return classes;
}

// NAV-11: the top bar's search field knows a focus moved there by Tab (assets/navigation/focus_origin.js) - a fake window
// whose capturing keydown listeners and timers the cases drive by hand
const focusWindow = { listeners: [], timers: [] };
focusWindow.addEventListener = (type, listener, capture) => focusWindow.listeners.push({ type, listener, capture });
focusWindow.setTimeout = (callback) => focusWindow.timers.push(callback);
const firstInstall = installFocusOrigin(focusWindow);
const secondInstall = installFocusOrigin(focusWindow);
const key = (event) => focusWindow.listeners.filter((l) => l.type === 'keydown').forEach((l) => l.listener(event));
const nextTask = () => focusWindow.timers.splice(0).forEach((callback) => callback());
const afterKey = (event) => {
    key(event);
    const during = focusMovedByTab();
    nextTask();

    return [during, focusMovedByTab()];
};
// Follow-up review F3: a focus event seen by the capturing listener - the window's own (it got the focus back) or an
// element's on its way down; [returning during the task, after it, tabbing during it]
const afterFocus = (target) => {
    focusWindow.listeners.filter((l) => l.type === 'focus').forEach((l) => l.listener({ type: 'focus', target }));
    const during = [focusReturnedWithWindow(), focusMovedByTab()];
    nextTask();

    return [during[0], focusReturnedWithWindow(), during[1]];
};

function standaloneCases(file) {
    return {
        'display-mode standalone': standalone({ displayMode: true }, file),
        'iOS home screen': standalone({ iosStandalone: true }, file),
        'a browser tab': standalone({}, file),
        'the test flag under WebDriver': standalone({ webdriver: true, flag: '1' }, file),
        'the test flag without WebDriver': standalone({ flag: '1' }, file),
        'WebDriver without the flag': standalone({ webdriver: true }, file),
        'inside the app': standalone({ displayMode: true, nativeApp: true }, file),
        'matchMedia throws': standalone({ matchMediaThrows: true }, file),
        'storage blocked under WebDriver': standalone({ webdriver: true, storageThrows: true }, file),
    };
}

const results = {
    // NAV review F3: a page of 600 px scroll range; iOS's rubber band reports 640, 620 while a fling bounces at the end
    rubberBand: {
        clamped: scroll([0, 300, 600, 640, 620, 600], true, 600),
        unclamped: scroll([0, 300, 600, 640, 620, 600], true),
        backUpForReal: scroll([0, 300, 600, 640, 600, 580], true, 600),
        topBounce: scroll([0, 300, 0, -40, -10], true, 600),
        shortPage: scroll([0, 40, 48], true, 48),
    },
    clampScroll: [clampScroll(-20, 600), clampScroll(700, 600), clampScroll(300, 600), clampScroll(Number.NaN, 600), clampScroll(900), clampScroll(900, -5)],
    // NAV-07 / NAV review F1: <html> back to neutral before the next page renders, off it when the controller goes
    reset: {
        hidden: reset({ 'data-topbar': 'hidden', 'data-topbar-scrolled': '', lang: 'en' }),
        remove: reset({ 'data-topbar': 'hidden', 'data-topbar-scrolled': '', lang: 'en' }, { remove: true }),
        nothing: reset({ lang: 'en' }),
    },
    focusOrigin: {
        install: [firstInstall, secondInstall, focusWindow.listeners.map((l) => [l.type, l.capture])],
        before: focusMovedByTab(),
        tab: afterKey({ key: 'Tab' }),
        shiftTab: afterKey({ key: 'Tab', shiftKey: true }),
        ctrlTab: afterKey({ key: 'Tab', ctrlKey: true }),
        // Follow-up review F4: Safari's Option+Tab is its Tab through links and buttons
        altTab: afterKey({ key: 'Tab', altKey: true }),
        metaTab: afterKey({ key: 'Tab', metaKey: true }),
        letter: afterKey({ key: 'k' }),
        ctrlK: afterKey({ key: 'k', ctrlKey: true }),
        windowBack: afterFocus(focusWindow),
        elementFocus: afterFocus({ nodeName: 'INPUT' }),
        returningBefore: focusReturnedWithWindow(),
    },

    constants: { HIDE_AFTER_PX, SHOW_AFTER_PX, PHONE_TOPBAR_PX, SHORT_VIEWPORT_PX },

    // The Verify's steps: scrollTo 400 at once hides it, 20 px up brings it back
    'scroll to 400, then 20 up': scroll([0, 400, 380]),
    // 8 px past the bar's height hide it, 7 px do not
    '7 px down past the bar': scroll([0, 52, 59]),
    '8 px down past the bar': scroll([0, 52, 60]),
    // Near the top it always shows
    'down to 52 never hides': scroll([0, 30, 52]),
    'hidden, then back near the top': scroll([0, 300, 40]),
    // Up 7 px stays hidden, 8 px shows; a change of direction counts from where it happened
    'hidden, 7 px up': scroll([0, 300, 293]),
    'hidden, 8 px up': scroll([0, 300, 292]),
    'shown again, then 7 px down': scroll([0, 300, 280, 287]),
    'shown again, then 8 px down': scroll([0, 300, 280, 288]),
    // Small steps add up in one direction
    'four 2 px steps down past the bar': scroll([60, 62, 64, 66, 68]),
    // Rubber band (iOS) above the top
    'negative scroll': scroll([0, -40]),
    // Not allowed to hide: never hidden, and counting starts again once allowed
    'may not hide': scroll([0, 400, 800], false),
    'shadow once scrolled': [nextTopbarState(null, 0, true).scrolled, nextTopbarState(null, 1, true).scrolled],

    mayHide: {
        phone: topbarMayHide(false, phone),
        desktop: topbarMayHide(false, { ...phone, phone: false }),
        'phone 479 px tall': topbarMayHide(false, { ...phone, viewportHeight: 479 }),
        'phone 480 px tall': topbarMayHide(false, { ...phone, viewportHeight: 480 }),
        'search open': topbarMayHide(false, { ...phone, searchOpen: true }),
        'Menu open': topbarMayHide(false, { ...phone, menuOpen: true }),
        'a menu of the bar open': topbarMayHide(false, { ...phone, popupOpen: true }),
        'focus inside the bar': topbarMayHide(false, { ...phone, focusInside: true }),
        'no facts': topbarMayHide(false),
    },

    translateY: ['none', '', '10px', '0px -52px', '0px -26.5px 0px', null].map(translateY),
    transformY: ['none', 'matrix(1, 0, 0, 1, 0, -52)', 'matrix(1, 0, 0, 1, -20, 0)', 'matrix3d(1, 0, 0, 0, 0, 1, 0, 0, 0, 0, 1, 0, 0, -30, 0, 1)', 'rotate(1deg)', null].map(transformY),
    stuckAt: {
        'at its sticky offset': stuckAt(52, 52),
        'half a pixel off': stuckAt(52.4, 52),
        'further down the page': stuckAt(300, 52),
        'stuck and translated by the hidden bar': stuckAt(0, 52, -52),
        'not stuck, translated': stuckAt(248, 52, -52),
        'no offset': stuckAt(52, NaN),
    },
    followers: STICKY_FOLLOWERS,

    standalone: standaloneCases('../assets/head/standalone.js'),
    // What the page inlines: its minified build (tools/build-inline-scripts.mjs) - the same answers
    standaloneBuild: standaloneCases('../templates/_inline_scripts/standalone.js'),
};

process.stdout.write(JSON.stringify(results));
