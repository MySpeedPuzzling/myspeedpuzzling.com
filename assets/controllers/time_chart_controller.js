import { Controller } from '@hotwired/stimulus';

/*
 * Writes the caption of a reference line (a dataset with `referenceCaption`, e.g. "Median 00:53:28" from
 * PlayerPuzzleTimesChart) just above its line at the left of the plot, on a light backing so a crossing
 * data line never makes it unreadable. Below the line when there is no room above it.
 */
const referenceCaptionsPlugin = {
    id: 'referenceCaptions',
    afterDatasetsDraw(chart) {
        const { ctx, chartArea } = chart;

        chart.data.datasets.forEach((dataset, index) => {
            const meta = chart.getDatasetMeta(index);

            if (!dataset.referenceCaption || meta.hidden || meta.data.length === 0) {
                return;
            }

            const lineY = meta.data[0].y;

            if (lineY < chartArea.top || lineY > chartArea.bottom) {
                return;
            }

            ctx.save();
            ctx.font = '600 10px system-ui, -apple-system, "Segoe UI", sans-serif';
            const width = ctx.measureText(dataset.referenceCaption).width;
            const height = 13;
            const x = chartArea.left + 4;
            const above = lineY - height - 2 >= chartArea.top;
            const y = above ? lineY - height - 2 : lineY + 3;

            ctx.fillStyle = 'rgba(255, 255, 255, 0.85)';
            ctx.fillRect(x - 2, y, width + 4, height);
            ctx.fillStyle = dataset.borderColor;
            ctx.textBaseline = 'top';
            ctx.fillText(dataset.referenceCaption, x, y + 2);
            ctx.restore();
        });
    },
};

export default class extends Controller {
    static targets = ['zoomButton'];

    connect() {
        this.canvasElement = this.element.querySelector('canvas');

        // Pre-load chart.js (already cached by ux-chartjs controller, resolves instantly)
        import('chart.js/auto').then(mod => { this._ChartClass = mod.default; });

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

        // JSON cannot carry a plugin: reference lines (a dataset with `referenceCaption`) get their caption here
        if ((config.data?.datasets || []).some((dataset) => dataset.referenceCaption)) {
            config.plugins = [...(config.plugins || []), referenceCaptionsPlugin];
        }
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

        // Reference lines are read from their caption, not from a tooltip on every point
        options.plugins.tooltip.filter = (item) => !item.dataset.referenceCaption;

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
        return this._ChartClass ? this._ChartClass.getChart(this.canvasElement) : null;
    }
}
