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
