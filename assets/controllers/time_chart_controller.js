import { Controller } from '@hotwired/stimulus';
import Chart from 'chart.js/auto';

export default class extends Controller {
    static targets = ['zoomButton'];

    connect() {
        this.canvasElement = this.element.querySelector('canvas');
        this.canvasElement.style.touchAction = 'pan-y';

        this.element.addEventListener('chartjs:pre-connect', this._onPreConnect.bind(this));
        this.element.addEventListener('chartjs:view-value-change', this._onViewValueChanged.bind(this));
    }

    disconnect() {
        this.element.removeEventListener('chartjs:pre-connect', this._onPreConnect.bind(this));
        this.element.removeEventListener('chartjs:view-value-change', this._onViewValueChanged.bind(this));
    }

    _onPreConnect(event) {
        const config = event.detail.config;

        this.applyOptions(config.options);
    }

    _onViewValueChanged(event) {
        const options = event.detail.options;

        this.applyOptions(options);
    }

    resetZoom() {
        const chart = this.chart;
        if (chart) {
            chart.resetZoom();
            this._toggleResetZoomButton(false);
            this.canvasElement.style.touchAction = 'pan-y';
        }
    }

    applyOptions(options) {
        this._toggleResetZoomButton(false);

        options.maintainAspectRatio = false;

        if (!options.scales) {
            options.scales = {};
        }
        if (!options.scales.x) {
            options.scales.x = {};
        }

        options.scales.x = {
            type: 'category',
            min: 10,
        };



        if (!options.scales.y) {
            options.scales.y = {};
        }

        options.scales.y = {
            type: 'linear',
            beginAtZero: true,
            ticks: {
                stepSize: 30 * 60, // Step size of 30 minutes in seconds
                callback: function (value) {
                    const hours = Math.floor(value / 3600);
                    const minutes = Math.floor((value % 3600) / 60);
                    const seconds = value % 60;
                    return `${hours}:${minutes.toString().padStart(2, '0')}:${seconds
                        .toString()
                        .padStart(2, '0')}`;
                },
            }
        };

        if (!options.plugins) {
            options.plugins = {};
        }

        if (!options.plugins.tooltip) {
            options.plugins.tooltip = {};
        }

        options.plugins.tooltip.callbacks = {
            label: function (context) {
                const value = context.raw;
                const hours = Math.floor(value / 3600);
                const minutes = Math.floor((value % 3600) / 60);
                const seconds = value % 60;
                return `${hours}:${minutes.toString().padStart(2, '0')}:${seconds
                    .toString()
                    .padStart(2, '0')}`;
            },
        };

        if (!options.plugins.zoom) {
            options.plugins.zoom = {};
        }

        options.plugins.zoom = {
            zoom: {
                drag: {
                    enabled: true,
                },
                pinch: {
                    enabled: true,
                },
                mode: 'x',
                onZoomComplete: () => {
                    this._toggleResetZoomButton(true);
                    this.canvasElement.style.touchAction = 'none';
                },
                limits: {
                    x: {
                        minRange: 10,
                    },
                }
            },
            pan: {
                enabled: true,
                modifierKey: 'shift',
                mode: 'x',
            },
            resetZoom: {
                onResetZoomComplete: () => {
                    this._toggleResetZoomButton(false);
                    this.canvasElement.style.touchAction = 'none';
                },
            },
        };
    }

    _toggleResetZoomButton(show) {
        if (this.hasZoomButtonTarget) {
            this.zoomButtonTarget.classList.toggle('d-none', !show);
            this.zoomButtonTarget.classList.toggle('d-inline-block', show);
        }
    }

    get chart() {
        return Chart.getChart(this.canvasElement);
    }
}
