// Platform detection for native apps (must run before anything else)
(function() {
    const userAgent = navigator.userAgent || '';

    if (userAgent.includes('Turbo Native iOS') || userAgent.includes('MySpeedPuzzling iOS')) {
        window.nativePlatform = 'ios';
    } else if (userAgent.includes('Turbo Native Android') || userAgent.includes('MySpeedPuzzling Android')) {
        window.nativePlatform = 'android';
    } else {
        window.nativePlatform = 'web';
    }

    window.isNativeApp = window.nativePlatform !== 'web';

    // Add classes to document root for CSS targeting
    document.documentElement.classList.add('platform-' + window.nativePlatform);
    if (window.isNativeApp) {
        document.documentElement.classList.add('native-app');
    }
})();

import './styles/app.scss';

// start the Stimulus application
import './bootstrap';

// Bootstrap - selective JS imports via named exports (tree-shakes unused components)
import { Modal, Dropdown, Collapse, Tab, Toast } from 'bootstrap';
import 'bootstrap-icons/font/bootstrap-icons.min.css';

// Expose on window.bootstrap for controllers that use bootstrap.Modal etc.
window.bootstrap = { Modal, Dropdown, Collapse, Tab, Toast };

import * as Turbo from '@hotwired/turbo';
import './feedback_modal.js'
import './turbo-stream-actions.js'

Turbo.config.drive.progressBarDelay = 0;

// Force native browser navigation for back/forward (restoration visits)
// This ensures iOS swipe-back, browser back/forward buttons all work identically to a non-Turbo site
document.addEventListener('turbo:visit', function(event) {
    if (event.detail.action === 'restore') {
        event.preventDefault();
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
if (!window.isNativeApp && 'serviceWorker' in navigator) {
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
