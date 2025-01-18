import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.element.addEventListener('chartjs:pre-connect', this._onPreConnect.bind(this));
    }

    disconnect() {
        this.element.removeEventListener('chartjs:pre-connect', this._onPreConnect.bind(this));
    }

    _onPreConnect(event) {
        const config = event.detail.config;

        config.options.maintainAspectRatio = false;

        if (!config.options.scales) {
            config.options.scales = {};
        }
        if (!config.options.scales.y) {
            config.options.scales.y = {};
        }

        config.options.scales = {
            y: {
                beginAtZero: true,
                ticks: {
                    stepSize: 900, // Step size of 15 minutes in seconds
                    callback: function (value) {
                        const hours = Math.floor(value / 3600);
                        const minutes = Math.floor((value % 3600) / 60);
                        const seconds = value % 60;
                        return `${hours}:${minutes.toString().padStart(2, '0')}:${seconds
                            .toString()
                            .padStart(2, '0')}`;
                    },
                }
            }
        };

        if (!config.options.plugins) {
            config.options.plugins = {};
        }

        if (!config.options.plugins.tooltip) {
            config.options.plugins.tooltip = {};
        }

        config.options.plugins.tooltip.callbacks = {
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
    }
}
