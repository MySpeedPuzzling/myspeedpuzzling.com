// Every native-aware module's decision, run once inside the Hotwire Native app and once on the web
// (docs/features/navigation NAV-14 (b), implementation plan §3.5 item 4): inside the app each one must give the
// web-neutral answer (no history entry, no pull, no vibration, no view transition, no snapshot restore - "leave"),
// on the web its usual one. tests/NativeAppStepAsideTest.php holds the expected answers and prints them per row.
//
// Adding a module: import its pure decision function here, add a row { id, run(root) } that calls it with the
// platform flags of `root` (a fake <html>), list the file in NATIVE_AWARE_MODULES (tests/PlatformFlagReadersTest.php)
// and the expected answers in NativeAppStepAsideTest::ROWS.

import { isNativeApp, nativePlatform, os } from '../assets/platform.js';
import { closeAction, entryAction } from '../assets/layer_history.js';
import { reportsSuppressed } from '../assets/confirm_dialog.js';
import { menuLinkAction } from '../assets/navigation/menu_links.js';
import { menuButtonState } from '../assets/navigation/nav_layout.js';
import { topbarMayHide } from '../assets/topbar_scroll.js';
import { historyAction, scrollRestore, takesOver } from '../assets/player_results_state.js';
import { shouldOpen } from '../assets/player_card_rules.js';
import { decideFor } from '../assets/tab_reselect.js';
import { pullEnabledFor } from '../assets/pull_to_refresh.js';
import { browserEnv, haptic } from '../assets/haptics.js';
import { longPressEnabledFor } from '../assets/tab_long_press.js';

/** A fake <html>: inside the app on an Android phone, or Chrome on the same phone */
function root(nativeApp) {
    const attributes = nativeApp ? { 'data-native-app': 'android', 'data-os': 'android' } : { 'data-os': 'android' };

    return {
        getAttribute: (name) => (name in attributes ? attributes[name] : null),
        classList: { contains: () => false },
    };
}

const ROWS = [
    // F (NAV-14 foundation): the flags themselves - the app is native, the device OS stays the device OS
    { id: 'assets/platform.js', run: (html) => ({ isNativeApp: isNativeApp(html), nativePlatform: nativePlatform(html), os: os(html) }) },
    // GEST-01 (f): no history entry for a layer inside the app (none pushed, none swapped), and so no history.back()
    // to close it
    {
        id: 'assets/layer_history.js',
        run: (html) => ({
            open: entryAction(isNativeApp(html), null),
            openOverAnotherLayer: entryAction(isNativeApp(html), 'menu'),
            close: closeAction(isNativeApp(html), 'menu', 'menu'),
        }),
    },
    // GEST-13: the confirm watchdog reports a native confirm an iPhone refused at once - never inside the app (the
    // P0-03 channel is web-only). The device here is an Android phone, so the iPhone is passed explicitly
    {
        id: 'assets/confirm_dialog.js',
        run: (html) => ({
            refusedOnAnIphone: reportsSuppressed(isNativeApp(html), 'ios', false, 0, false),
            refusedOnThisDevice: reportsSuppressed(isNativeApp(html), os(html), false, 0, false),
        }),
    },
    // MENU-02b / NAV-13: a plain click on a link to `menu` - the app's own Menu screen inside the app; on the web the
    // phone panel, the desktop overlay, the docked column's focus, the desktop ☰'s dock / fold
    {
        id: 'assets/navigation/menu_links.js',
        run: (html) => ({
            phone: menuLinkAction(isNativeApp(html), 'phone', {}),
            desktop: menuLinkAction(isNativeApp(html), 'overlay', {}),
            docked: menuLinkAction(isNativeApp(html), 'docked', {}),
            burgerFrom1280: menuLinkAction(isNativeApp(html), 'rail', {}, true),
        }),
    },
    // NAV-13: the desktop ☰ docks / folds the column on the web from 1280 px; the app has no column (never shown there)
    {
        id: 'assets/navigation/nav_layout.js',
        run: (html) => ({
            menuButtonAt1440: menuButtonState({ nativeApp: isNativeApp(html), wide: true, docked: true }).tooltip,
        }),
    },
    // NAV-03: the phone top bar hides on scroll on the web; the app has no web top bar - nothing to hide there
    {
        id: 'assets/topbar_scroll.js',
        run: (html) => ({
            scrolledDownOnAPhone: topbarMayHide(isNativeApp(html), { phone: true, viewportHeight: 800 }),
        }),
    },
    // PROF-05c: the Results page owns history entries on the web only - inside the app it writes none, takes no link
    // over (the app's own navigation decides) and never restores a saved scroll position
    {
        id: 'assets/player_results_state.js',
        run: (html) => ({
            sortChange: historyAction('discrete', 'https://msp.test/en/player-results/a', 'https://msp.test/en/player-results/a?sort=name', isNativeApp(html)),
            showMore: historyAction('more', 'https://msp.test/en/player-results/a', 'https://msp.test/en/player-results/a?page=2', isNativeApp(html)),
            takesOver: takesOver(isNativeApp(html)),
            backByFullLoad: scrollRestore({ nativeApp: isNativeApp(html), cameBack: true, saved: { y: 400, row: null, rowTop: null } }),
        }),
    },
    // CARD-01a: a plain tap on a player's name opens the card on the web; inside the app the listener steps aside and the
    // link navigates to the profile (assets/player_card.js asks shouldOpen() with the app flag)
    {
        id: 'assets/player_card.js',
        run: (html) => {
            const name = {
                getAttribute: (attribute) => ({ href: '/en/player-profile/0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b', 'data-player-card': '' })[attribute] ?? null,
                closest: () => null,
            };

            return { tapOnAName: shouldOpen({ button: 0, detail: 1, pointerType: 'touch' }, name, { nativeApp: isNativeApp(html) }) ? 'card' : 'profile' };
        },
    },
    // GEST-03: a tap on the active tab scrolls the Hub to its top / refreshes its feed on the web; inside the app there is
    // no web tab bar - the link does what it says
    {
        id: 'assets/tab_reselect.js',
        run: (html) => {
            const hub = { tabPlace: 'home', currentPlace: 'home', tabPath: '/en/hub', currentPath: '/en/hub' };

            return {
                hubScrolled: decideFor(html, { ...hub, scrollY: 800 }).action,
                hubAtTheTop: decideFor(html, { ...hub, scrollY: 0 }).action,
            };
        },
    },
    // GEST-06: our pull-to-refresh arms in the installed web app only - never in a browser tab, never inside the app
    // (the same <html> with the installed-app class added: inside the app the head script never sets it, a stray one
    // changes nothing)
    {
        id: 'assets/pull_to_refresh.js',
        run: (html) => ({
            installedApp: pullEnabledFor({ ...html, classList: { contains: (name) => name === 'is-standalone' } }),
            browserTab: pullEnabledFor(html),
        }),
    },
    // GEST-10: haptics play on an Android phone's browser (the API there, the user active, the page visible, the setting
    // on) - inside the app the backend is none until a bridge component exists (README §4.14 (c)): no vibration
    {
        id: 'assets/haptics.js',
        run: (html) => {
            const env = browserEnv({
                root: html,
                nav: { vibrate: () => true, userActivation: { hasBeenActive: true } },
                storage: null,
                doc: { visibilityState: 'visible' },
            });

            return { timeSaved: haptic('confirm', env), switchOn: haptic('toggleOn', env) };
        },
    },
    // GEST-18: a finger held on a cut tab shows its label on the web tab bar; the app has no web tab bar - no bubble
    {
        id: 'assets/tab_long_press.js',
        run: (html) => ({ heldOnACutTab: longPressEnabledFor(html, 'touch') }),
    },
];

const results = ROWS.map((row) => {
    const answer = (nativeApp) => {
        try {
            return { value: row.run(root(nativeApp)) };
        } catch (error) {
            return { threw: String(error) };
        }
    };

    return { id: row.id, app: answer(true), web: answer(false) };
});

process.stdout.write(JSON.stringify(results));
