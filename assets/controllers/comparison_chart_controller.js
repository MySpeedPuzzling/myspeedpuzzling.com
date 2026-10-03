import { Controller } from '@hotwired/stimulus';

/**
 * The charts on the compare page (docs/features/player-comparison.md "Charts", ComparisonChartsFactory).
 *
 * Eager on purpose, never lazy-loaded - like leaderboard_chart_controller.js: the plugin and the callbacks are handed
 * over in `chartjs:pre-connect`, which the lazily loaded Chart.js controller fires once, when it builds the chart.
 * Guarded by tests/StimulusControllerLoadingTest.php.
 *
 * The server sends plain Chart.js JSON plus `options.plugins.comparisonChart`; this controller adds what JSON cannot
 * carry. Every text comes from the server - this file only places it:
 * - `tooltips[datasetIndex][dataIndex]` = {title, lines} - a point without one (the parity line) has no tooltip,
 * - `ticks[axisId]` = [[value, label], ...] - exactly these ticks, with these labels (H:MM, "20 min", "+10 %"),
 * - `zeroLine` = {axis, color} - the zero gridline a step darker,
 * - `truncate` = {axis, wide, narrow, breakpoint} - category labels (puzzle names) cut shorter on a narrow chart,
 * - `valueLabels` = [{datasetIndex, index, text}] - a horizontal bar's value beside the zero line, on the empty side of
 *   its row (the diverging bars: a row only ever has a bar on one side),
 * - `endLabels` = [{datasetIndex, text}] - a line's name right of its last point,
 * - `labelColor` - ink for those labels (never the series colour).
 */
const LABEL_FONT_SIZE = 11;
const END_LABEL_MIN_GAP = 13;

function settings(chart) {
    return chart?.config?.options?.plugins?.comparisonChart || null;
}

function tooltipEntry(chart, datasetIndex, dataIndex) {
    const tooltips = settings(chart)?.tooltips;
    const dataset = tooltips ? tooltips[datasetIndex] : null;

    return dataset && dataset[dataIndex] ? dataset[dataIndex] : null;
}

function shorten(text, max) {
    const value = String(text ?? '');

    return value.length > max ? `${value.slice(0, Math.max(1, max - 1)).trimEnd()}…` : value;
}

function drawValueLabels(chart, labels, ctx) {
    labels.forEach((label) => {
        const meta = chart.getDatasetMeta(label.datasetIndex);
        const element = meta && !meta.hidden ? meta.data[label.index] : null;

        if (!element) {
            return;
        }

        // Horizontal bar: x is its tip, base the zero line, y its middle
        const { x, y, base } = element.getProps(['x', 'y', 'base'], true);
        const growsLeft = x < base;

        ctx.textAlign = growsLeft ? 'left' : 'right';
        ctx.fillText(label.text, growsLeft ? base + 5 : base - 5, y);
    });
}

function lastPoint(chart, datasetIndex) {
    const meta = chart.getDatasetMeta(datasetIndex);
    const data = chart.data.datasets[datasetIndex]?.data || [];

    if (!meta || meta.hidden) {
        return null;
    }

    for (let index = data.length - 1; index >= 0; index--) {
        if (data[index] !== null && data[index] !== undefined && meta.data[index]) {
            return meta.data[index].getProps(['x', 'y'], true);
        }
    }

    return null;
}

function drawEndLabels(chart, labels, ctx) {
    const placed = labels
        .map((label) => ({ label, point: lastPoint(chart, label.datasetIndex) }))
        .filter((item) => item.point !== null)
        .map((item) => ({ text: item.label.text, x: item.point.x + 6, y: item.point.y }))
        .sort((first, second) => first.y - second.y);

    // Two ends close together: spread the names around their middle, so neither covers the other
    for (let index = 1; index < placed.length; index++) {
        const gap = placed[index].y - placed[index - 1].y;

        if (gap < END_LABEL_MIN_GAP) {
            const shift = (END_LABEL_MIN_GAP - gap) / 2;
            placed[index - 1].y -= shift;
            placed[index].y += shift;
        }
    }

    ctx.textAlign = 'left';
    placed.forEach((item) => ctx.fillText(item.text, item.x, item.y));
}

const labelsPlugin = {
    id: 'comparisonChartLabels',

    afterDatasetsDraw(chart) {
        const config = settings(chart);

        if (!config || (!Array.isArray(config.valueLabels) && !Array.isArray(config.endLabels))) {
            return;
        }

        const ctx = chart.ctx;
        const fontFamily = chart.options.font && chart.options.font.family ? chart.options.font.family : 'sans-serif';

        ctx.save();
        ctx.font = `600 ${LABEL_FONT_SIZE}px ${fontFamily}`;
        ctx.fillStyle = config.labelColor || chart.options.color;
        ctx.textBaseline = 'middle';

        if (Array.isArray(config.valueLabels)) {
            drawValueLabels(chart, config.valueLabels, ctx);
        }

        if (Array.isArray(config.endLabels)) {
            drawEndLabels(chart, config.endLabels, ctx);
        }

        ctx.restore();
    },
};

function applyOptions(options) {
    const config = options?.plugins?.comparisonChart;

    if (!config) {
        return;
    }

    options.scales = options.scales || {};

    // Tooltips: the server's lines; points without any (the parity line) never show one
    const tooltip = options.plugins.tooltip || {};
    tooltip.filter = (item) => tooltipEntry(item.chart, item.datasetIndex, item.dataIndex) !== null;
    tooltip.callbacks = {
        ...(tooltip.callbacks || {}),
        title(items) {
            const entry = items.length > 0 ? tooltipEntry(items[0].chart, items[0].datasetIndex, items[0].dataIndex) : null;

            return entry ? entry.title : '';
        },
        label(item) {
            const entry = tooltipEntry(item.chart, item.datasetIndex, item.dataIndex);

            return entry ? entry.lines : '';
        },
    };
    options.plugins.tooltip = tooltip;

    // Exactly the server's ticks, labelled by the server
    Object.entries(config.ticks || {}).forEach(([axis, pairs]) => {
        const scale = options.scales[axis];

        if (!scale || !Array.isArray(pairs)) {
            return;
        }

        const labels = new Map(pairs.map(([value, label]) => [Number(value), label]));

        scale.afterBuildTicks = (axisScale) => {
            axisScale.ticks = pairs.map(([value]) => ({ value: Number(value) }));
        };
        scale.ticks = {
            ...(scale.ticks || {}),
            callback: (value) => (labels.has(Number(value)) ? labels.get(Number(value)) : ''),
        };
    });

    if (config.zeroLine && options.scales[config.zeroLine.axis]) {
        const scale = options.scales[config.zeroLine.axis];
        const grid = scale.grid || {};
        const color = grid.color;

        scale.grid = {
            ...grid,
            color: (context) => (context.tick && Number(context.tick.value) === 0 ? config.zeroLine.color : color),
        };
    }

    if (config.truncate && options.scales[config.truncate.axis]) {
        const scale = options.scales[config.truncate.axis];
        const { wide, narrow, breakpoint } = config.truncate;

        scale.ticks = {
            ...(scale.ticks || {}),
            callback(value) {
                return shorten(this.getLabelForValue(value), this.chart.width < breakpoint ? narrow : wide);
            },
        };
    }
}

export default class extends Controller {
    connect() {
        this._onPreConnect = this._onPreConnect.bind(this);
        this._onViewValueChange = this._onViewValueChange.bind(this);

        this.element.addEventListener('chartjs:pre-connect', this._onPreConnect);
        this.element.addEventListener('chartjs:view-value-change', this._onViewValueChange);
    }

    disconnect() {
        this.element.removeEventListener('chartjs:pre-connect', this._onPreConnect);
        this.element.removeEventListener('chartjs:view-value-change', this._onViewValueChange);
    }

    _onPreConnect(event) {
        const config = event.detail.config;

        config.plugins = [...(config.plugins || []), labelsPlugin];
        applyOptions(config.options);
    }

    // A re-render of the page (Live Component) hands the chart plain JSON options again
    _onViewValueChange(event) {
        applyOptions(event.detail.options);
    }
}
