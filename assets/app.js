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

import 'simplebar'; // or "import SimpleBar from 'simplebar';" if you want to use it manually.
import 'simplebar/dist/simplebar.css';

// Twitter bootstrap
import 'bootstrap';
import 'bootstrap-icons/font/bootstrap-icons.min.css';

import './feedback_modal.js'
import './turbo-stream-actions.js'

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
