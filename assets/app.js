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

import zoomPlugin from 'chartjs-plugin-zoom';

// register globally for all charts
document.addEventListener('chartjs:init', function (event) {
    const Chart = event.detail.Chart;
    Chart.register(zoomPlugin);
});
