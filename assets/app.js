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
// The URL a restore visit reloads below, for the page it brings back (the Results page's scroll position, PROF-05c)
import { markRestoreReload } from './restore_reload.js';

// When each page's HTML arrived, before any lazy controller loads - the stopwatch pill's clock (ADD-03 review F5)
installDocumentReceipts({ window, document });

// The Help sheet's openers: the ?url= of the page as it is at the tap, the help page's own box on the help page
// (HELP review F4, F5)
installHelpOpeners({ window, document, layers });

// The Puzzles place opens the section used last: each Puzzles section page records itself, the place's links follow
// (PUZ-01, on every turbo:load)
installPuzzlesSection({ window, document });

Turbo.config.drive.progressBarDelay = 0;

// Navigation clicks, the viewport once per session and Back/Forward navigations (docs/features/navigation, P0-03).
// The web chrome only - the app's navigation is native
const navInstrumentation = isNativeApp() ? null : startNavInstrumentation({ window, document });

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

// Force native browser navigation for back/forward (restoration visits)
// This ensures iOS swipe-back, browser back/forward buttons all work identically to a non-Turbo site
// Inside the app the native adapter owns Back: its restore visits are left alone (a reload would turn the native Back
// into a full WebView load)
document.addEventListener('turbo:visit', function(event) {
    if (isNativeApp()) {
        return;
    }

    if (event.detail.action === 'restore') {
        event.preventDefault();
        // Counted here: the reload below is a replace navigation, not a traverse, so it is not counted again
        if (navInstrumentation) {
            navInstrumentation.turboRestore();
        }
        markRestoreReload(event.detail.url);
        window.location.href = event.detail.url;
    }
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
