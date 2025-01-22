import { Controller } from '@hotwired/stimulus';
import Chart from 'chart.js/auto';

export default class extends Controller {
    static targets = ['zoomButton'];

    connect() {
        this.canvasElement = this.element.querySelector('canvas');

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
        }
    }

    applyOptions(options) {
        this._toggleResetZoomButton(false);

        options.maintainAspectRatio = false;

        if (!options.transitions) {
            options.transitions = {};
        }

        options.transitions = {
            zoom: {
                animation: {
                    duration: 200,
                    easing: 'easeOutCubic'
                }
            }
        };

        if (!options.scales) {
            options.scales = {};
        }
        if (!options.scales.y) {
            options.scales.y = {};
        }

        options.scales.y = {
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
                }
            },
            pan: {
                enabled: false,
                modifierKey: 'shift',
                mode: 'x',
            },
            resetZoom: {
                onResetZoomComplete: () => {
                    this._toggleResetZoomButton(false);
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
