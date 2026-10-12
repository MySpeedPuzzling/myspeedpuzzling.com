// Runs assets/topbar_scroll.js (the phone top bar's hide on scroll, NAV-03) and the installed-app head script
// assets/head/standalone.js through the cases tests/TopbarScrollScriptTest.php asks for; prints one JSON object.

import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import {
    HIDE_AFTER_PX,
    nextTopbarState,
    PHONE_TOPBAR_PX,
    SHORT_VIEWPORT_PX,
    SHOW_AFTER_PX,
    STICKY_FOLLOWERS,
    stuckAt,
    topbarMayHide,
    transformY,
    translateY,
} from '../assets/topbar_scroll.js';

/** Scrolls through the positions, returns whether the bar is hidden after each */
function scroll(positions, mayHide = true) {
    let state = null;

    return positions.map((y) => {
        state = nextTopbarState(state, y, mayHide);

        return state.hidden;
    });
}

const phone = { phone: true, viewportHeight: 800, searchOpen: false, menuOpen: false, popupOpen: false, focusInside: false };

/** Runs the head script on a fake window; returns the classes it put on <html> (null when it threw) */
function standalone({ displayMode = false, iosStandalone = false, webdriver = false, flag = null, nativeApp = false, matchMediaThrows = false, storageThrows = false } = {}) {
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
        vm.runInNewContext(readFileSync(new URL('../assets/head/standalone.js', import.meta.url), 'utf8'), { window });
    } catch (e) {
        return null;
    }

    return classes;
}

const results = {
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

    standalone: {
        'display-mode standalone': standalone({ displayMode: true }),
        'iOS home screen': standalone({ iosStandalone: true }),
        'a browser tab': standalone(),
        'the test flag under WebDriver': standalone({ webdriver: true, flag: '1' }),
        'the test flag without WebDriver': standalone({ flag: '1' }),
        'WebDriver without the flag': standalone({ webdriver: true }),
        'inside the app': standalone({ displayMode: true, nativeApp: true }),
        'matchMedia throws': standalone({ matchMediaThrows: true }),
        'storage blocked under WebDriver': standalone({ webdriver: true, storageThrows: true }),
    },
};

process.stdout.write(JSON.stringify(results));
