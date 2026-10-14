import { isNativeApp, nativePlatform } from './platform.js';

// Inside the Hotwire Native app? Derived from the server-rendered app flag on <html> through assets/platform.js, the
// only reader of the platform flags (docs/features/navigation/README.md §4.14). The globals and classes stay for older
// code and the CSS and agree with the flag by construction; new code calls isNativeApp() / nativePlatform() instead.
(function() {
    const platform = nativePlatform() ?? 'web';

    window.nativePlatform = platform;
    window.isNativeApp = platform !== 'web';

    // Add classes to document root for CSS targeting
    document.documentElement.classList.add('platform-' + platform);
    if (platform !== 'web') {
        document.documentElement.classList.add('native-app');
    }
})();

import './styles/app.scss';
import './styles/live_refresh.scss';

// start the Stimulus application
import './bootstrap';

// Bootstrap - selective JS imports via named exports (tree-shakes unused components)
import { Modal, Dropdown, Collapse, Tab, Toast } from 'bootstrap';
import 'bootstrap-icons/font/bootstrap-icons.min.css';

// Expose on window.bootstrap for controllers that use bootstrap.Modal etc.
window.bootstrap = { Modal, Dropdown, Collapse, Tab, Toast };

import * as Turbo from '@hotwired/turbo';
import './turbo-stream-actions.js'
// Back closes the top layer: the one owner of history entries next to Turbo, listening on every page (GEST-01)
import layers from './layer_history.js';
// The desktop Menu column / rail: the docked-or-rail choice, folded groups, the column's scroll (NAV-13; CSS lays it out)
import './navigation/nav_layout.js';
// A Turbo visit lands at its top at once - never a smooth glide from the previous page's offset (NAV-07)
import './navigation/visit_scroll.js';
import { startNavInstrumentation } from './nav_instrumentation.js';
import { installConfirmDialog } from './confirm_dialog.js';
import { installDocumentReceipts } from './document_receipt.js';
import { installHelpOpeners } from './help_openers.js';
import { installPuzzlesSection } from './puzzles_section.js';
import { installPlayerCard } from './player_card.js';
import { install as installHaptics } from './haptics.js';
import { install as installPressFeedback } from './press_feedback.js';
import { install as installViewTransitionGate } from './view_transition_gate.js';
// Instant Back (GEST-14/15): Turbo's snapshot of a browse page on Back, the clean copy it caches, the staleness rules
import { installInstantBack } from './instant_back.js';
import { installBackMeasurement } from './back_measurement.js';
import { applyMenuOrder, DOCK } from './navigation/menu_order.js';

// When each page's HTML arrived, before any lazy controller loads - the stopwatch pill's clock (ADD-03 review F5)
installDocumentReceipts({ window, document });

// The Help sheet's openers: the ?url= of the page as it is at the tap, the help page's own box on the help page
// (HELP review F4, F5)
installHelpOpeners({ window, document, layers });

// The Puzzles place opens the section used last: each Puzzles section page records itself, the place's links follow
// (PUZ-01, on every turbo:load)
installPuzzlesSection({ window, document });

// A click on a player's name opens their card - a sheet on phones, a popover from 576 px (CARD-01a); the layer itself
// loads on the first hover, focus or open
installPlayerCard({ window, document });

// Haptics (GEST-10, assets/haptics.js - Android only, the device's Vibration setting): a switch turned on, a refused
// form (422), a form's successful submit that names its haptic (the add / edit form: "time saved")
installHaptics({ document });

Turbo.config.drive.progressBarDelay = 0;

// Pressed states within 100 ms (GEST-04, assets/styles/_press-states.scss): iOS Safari applies :active only to elements
// with a touch listener above them (WebKit bug 138816) - one empty passive listener on the document gives every
// [data-press] target its state layer at touch, without delaying the tap or the scroll
document.addEventListener('touchstart', () => {}, { passive: true });

// Android's ripple from the touch point on the same [data-press] targets (GEST-11, assets/press_feedback.js): on top of
// the state layer, never on iOS or desktop, never under reduced motion
installPressFeedback({ window, document });

// Which visits run as a view transition (GEST-12, GEST-17; assets/view_transition_gate.js): Android's fade through on
// place switches, the thumbnail → puzzle page morph on both phones - never on Back, iOS / desktop switch instantly
installViewTransitionGate({ window, document });

// Navigation clicks and the viewport once per session (docs/features/navigation, P0-03); its Back/Forward lines are
// GEST-08's now (assets/back_measurement.js: one line per Back with how the page came back - snapshot, reload, bfcache,
// bfcache-reload - and ms_to_load). The web chrome only - the app's navigation is native
const navInstrumentation = isNativeApp() ? null : startNavInstrumentation({ window, document, history: false });

if (navInstrumentation) {
    installBackMeasurement({ window, document, send: (line) => navInstrumentation.back(line) });
}

// In-page confirmations (GEST-13): data-turbo-confirm and the controllers ask in MSP's own <dialog>, never through
// window.confirm (iOS Safari silently refuses native dialogs after a Back to a pushState entry, hotwired/turbo#637);
// the watchdog reports a native confirm dialog an iPhone still refused on the P0-03 channel
installConfirmDialog({
    Turbo,
    report: (detail) => {
        if (navInstrumentation) {
            navInstrumentation.dialogSuppressed(detail);
        }
    },
});

// Back and Forward (README §4.13 G9, assets/instant_back.js): a restore visit renders Turbo's snapshot of a browse page
// that opted in (turbo-cache-control no-preview), cached less than 10 minutes ago for the same viewer - otherwise it is a
// full load of the URL, as before (INSTANT_BACK = false turns every restore into one again). What Turbo caches is a
// clean copy: no open layer, modal or menu, no widget that builds its own DOM. Inside the app the native adapter owns
// Back: its restore visits are left alone (a reload would turn the native Back into a full WebView load)
installInstantBack({
    turbo: () => Turbo,
    layers,
    menuOrder: (nav) => applyMenuOrder(nav, DOCK),
});

// After Turbo morph/render, cached images lose the "loaded" class.
// Re-check all lazy images and mark already-complete ones as loaded.
function revealCachedLazyImages() {
    document.querySelectorAll('img.lazy-img:not(.loaded)').forEach(function (img) {
        if (img.complete && img.naturalWidth > 0) {
            img.classList.add('loaded');
        }
    });
}
document.addEventListener('turbo:render', revealCachedLazyImages);
document.addEventListener('turbo:morph', revealCachedLazyImages);
document.addEventListener('turbo:frame-render', revealCachedLazyImages);

// register zoom plugin only when a chart initializes (lazy-loaded)
document.addEventListener('chartjs:init', function (event) {
    import('chartjs-plugin-zoom').then(function (module) {
        const Chart = event.detail.Chart;
        Chart.register(module.default);
    });
});

// Service Worker registration (skip in native apps)
if (!isNativeApp() && 'serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('/service-worker.js')
            .then(function (registration) {
                // Check for updates every 60 minutes (for long-lived tabs like stopwatch)
                setInterval(function () {
                    registration.update();
                }, 60 * 60 * 1000);
            })
            .catch(function (error) {
                console.warn('SW registration failed:', error);
            });
    });
}
