/**
 * Turbo for the live entry page inside the native apps only (competition toolkit C3,
 * docs/features/competitions-management/live-results.md "The page").
 *
 * The iOS/Android apps are Hotwire Native wrappers: their WebView finishes a visit only when the page runs Turbo - the
 * script the apps inject registers its adapter on `window.Turbo` and reports "page loaded"; on a page without Turbo it
 * reports a failed load after its timeout (4 s Android, 30 s iOS) and the app shows its error screen over the page.
 * The live entry page runs no Turbo on the web (its own lean bundle, assets/live_entry.js), so the layout adds this entry
 * for native-app user agents only (`is_native_app()`).
 *
 * Drive stays off: links and the round switch stay plain page loads exactly as on the web (the page's own unsent-results
 * guard keeps working) - the apps route those navigations natively. Turbo is here only for the apps' adapter.
 */

import * as Turbo from '@hotwired/turbo';

Turbo.session.drive = false;
