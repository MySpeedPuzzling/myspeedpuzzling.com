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
