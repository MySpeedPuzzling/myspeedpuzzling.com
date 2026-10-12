// The one reader of the platform flags on <html> (docs/features/navigation/README.md §4.13 + §4.14). Two different
// things, never mixed:
// - data-native-app="ios|android": inside the Hotwire Native app, rendered by the server (native_app_attribute());
//   isNativeApp() / nativePlatform(). Everything web-only steps aside when it is set.
// - data-os="ios|android|other": the device's operating system in any browser, set before first paint by the head
//   script assets/head/platform_os.js; os(). A browser on an iPhone is os() === 'ios' and not native.
// - html.is-standalone: the installed web app (display-mode standalone), set by a head script; isStandalone().
//
// Pure: no DOM access when imported, the attributes are read on every call (nothing cached, so the import order never
// matters). Every function takes an optional root element so node tests can pass a fake one.

const NATIVE_PLATFORMS = ['ios', 'android'];
const OPERATING_SYSTEMS = ['ios', 'android', 'other'];

function documentRoot() {
    return typeof document !== 'undefined' && document ? document.documentElement : null;
}

function attribute(root, name) {
    const element = root === undefined ? documentRoot() : root;

    if (!element || typeof element.getAttribute !== 'function') {
        return null;
    }

    return element.getAttribute(name);
}

/** 'ios' | 'android' inside the app, null on the web (a junk value counts as the web) */
export function nativePlatform(root) {
    const value = attribute(root, 'data-native-app');

    return NATIVE_PLATFORMS.includes(value) ? value : null;
}

export function isNativeApp(root) {
    return nativePlatform(root) !== null;
}

/** The device's OS: 'ios' | 'android' | 'other' (no attribute or a junk value = 'other') */
export function os(root) {
    const value = attribute(root, 'data-os');

    return OPERATING_SYSTEMS.includes(value) ? value : 'other';
}

/** The installed web app - never true inside the native app */
export function isStandalone(root) {
    const element = root === undefined ? documentRoot() : root;

    if (!element || !element.classList || typeof element.classList.contains !== 'function') {
        return false;
    }

    return element.classList.contains('is-standalone') && !isNativeApp(element);
}
