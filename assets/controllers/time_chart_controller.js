import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
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

    applyOptions(options) {
        options.maintainAspectRatio = false;

        if (!options.scales) {
            options.scales = {};
        }
        if (!options.scales.y) {
            options.scales.y = {};
        }

        options.scales.y = {
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
    }
}
