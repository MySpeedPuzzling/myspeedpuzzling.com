/**
 * The live entry page's own bundle (competition toolkit C2, docs/features/competitions-management/judge-offline.md
 * "Lean layout + bundle (C2)"): templates/live_results/_layout.html.twig loads this instead of the site's `app` entry - no Turbo, no Live
 * Components, no Bootstrap JS, no other controllers. Only the live results controller, registered eagerly, so the page
 * is interactive as soon as this file runs (no lazy chunk after `app.js`).
 *
 * Everything the page needs is imported statically - never `import()`: a referee's device stores the files
 * entrypoints.json lists for this entry with the page's offline copy (service worker, toolkit C6), and a lazy chunk would
 * be missing offline (tests/LiveEntryBundleTest.php).
 */

import { Application } from '@hotwired/stimulus';
import LiveResultsController from './controllers/live_results_controller.js';

const application = Application.start();
application.register('live-results', LiveResultsController);

// The same service worker as every other page (registered in app.js there), skipped in the native apps
const userAgent = navigator.userAgent || '';
const nativeApp = /Turbo Native (iOS|Android)|MySpeedPuzzling (iOS|Android)/.test(userAgent);

if (!nativeApp && 'serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('/service-worker.js')
            .then(function (registration) {
                // Check for updates every 60 minutes (a referee's phone keeps this page open for hours)
                setInterval(function () {
                    registration.update();
                }, 60 * 60 * 1000);
            })
            .catch(function (error) {
                console.warn('SW registration failed:', error);
            });
    });
}
