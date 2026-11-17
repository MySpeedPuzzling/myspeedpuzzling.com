// The analytics studio page (templates/analytics_studio/page.html.twig, docs/features/competitions-management/
// analytics-studio.md §7.6) - the Encore entry `analytics_studio`: no Stimulus, no Turbo, nothing of the site (a plain
// entry keeps it within AnalyticsStudioBundleTest's budget); never precached for every visitor (service worker
// PRECACHE_SKIP_ENTRIES). The page adapter is assets/analytics-studio/page.js.

import './styles/analytics_studio.scss';
import { startAnalyticsStudio } from './analytics-studio/page.js';

function start() {
    const root = document.getElementById('analytics-studio');

    if (root !== null) {
        startAnalyticsStudio(root);
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
} else {
    start();
}
