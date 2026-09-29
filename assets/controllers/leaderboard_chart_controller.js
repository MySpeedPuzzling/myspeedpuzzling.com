/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Markers on the puzzle leaderboard chart (docs/features/puzzle-leaderboard-chart.md).
 *
 * The server sends plain Chart.js JSON with `options.plugins.leaderboardMarkers.markers`; this controller adds what JSON
 * cannot carry - an inline plugin drawing them. Three kinds:
 * - `vertical` (distribution): a line at `position` in bar units - bar i spans [i, i + 1), so 3.5 is the middle of the fourth bar,
 * - `horizontal` (a bar per row): a line at the y value `value`, labelled at its left end,
 * - `bar` (a bar per row): a label above bar `index`.
 * On the distribution it also titles each tooltip with the bar's time range (`ranges`); the bar-per-row chart keeps the
 * time-chart controller's tooltips and zoom.
 */
const LABEL_FONT_SIZE = 11;

function labelBox(ctx, text, x, y, align) {
    const width = ctx.measureText(text).width;
    const left = align === 'left' ? x : align === 'right' ? x - width : x - width / 2;

    return { left, right: left + width, top: y - LABEL_FONT_SIZE, bottom: y };
}

function overlaps(box, others) {
    return others.some((other) => box.left < other.right && box.right > other.left && box.top < other.bottom && box.bottom > other.top);
}

function drawLabel(ctx, marker, x, y, align, occupied) {
    ctx.setLineDash([]);
    ctx.fillStyle = marker.color;
    ctx.textAlign = align;
    ctx.fillText(marker.label, x, y);
    occupied.push(labelBox(ctx, marker.label, x, y, align));
}

// Centred on x, but never leaving the chart
function alignWithin(ctx, text, x, area) {
    const width = ctx.measureText(text).width;

    if (x - width / 2 < area.left) {
        return 'left';
    }

    return x + width / 2 > area.right ? 'right' : 'center';
}

function drawVerticalMarkers(chart, markers, occupied) {
    const barsCount = chart.data.labels ? chart.data.labels.length : 0;

    if (markers.length === 0 || barsCount === 0) {
        return;
    }

    const ctx = chart.ctx;
    const area = chart.chartArea;
    const xScale = chart.scales.x;
    const firstCenter = xScale.getPixelForValue(0);
    const barWidth = barsCount > 1 ? xScale.getPixelForValue(1) - firstCenter : area.right - area.left;

    const placed = markers.map((marker) => ({
        ...marker,
        x: Math.min(area.right, Math.max(area.left, firstCenter + (marker.position - 0.5) * barWidth)),
    }));

    placed.forEach((marker, index) => {
        ctx.strokeStyle = marker.color;
        ctx.lineWidth = 2;
        ctx.setLineDash(marker.dashed ? [4, 3] : []);
        ctx.beginPath();
        ctx.moveTo(marker.x, area.top);
        ctx.lineTo(marker.x, area.bottom);
        ctx.stroke();

        // Two labels close together: each one points away from the other
        const other = placed[1 - index];
        let align = alignWithin(ctx, marker.label, marker.x, area);

        if (other && Math.abs(other.x - marker.x) < ctx.measureText(marker.label).width + 8) {
            align = marker.x <= other.x ? 'right' : 'left';
        }

        drawLabel(ctx, marker, marker.x + (align === 'left' ? 3 : align === 'right' ? -3 : 0), area.top - 3, align, occupied);
    });
}

function drawBarLabel(chart, marker, occupied) {
    const ctx = chart.ctx;
    const area = chart.chartArea;
    const value = chart.data.datasets[0] ? chart.data.datasets[0].data[marker.index] : null;

    if (typeof value !== 'number') {
        return;
    }

    const x = chart.scales.x.getPixelForValue(marker.index);

    // Zoomed away from the viewer's bar
    if (x < area.left || x > area.right) {
        return;
    }

    const y = Math.max(chart.scales.y.getPixelForValue(value), area.top) - 3;
    drawLabel(ctx, marker, x, y, alignWithin(ctx, marker.label, x, area), occupied);
}

function drawHorizontalMarker(chart, marker, occupied) {
    const ctx = chart.ctx;
    const area = chart.chartArea;
    const y = chart.scales.y.getPixelForValue(marker.value);

    if (y < area.top || y > area.bottom) {
        return;
    }

    ctx.strokeStyle = marker.color;
    ctx.lineWidth = 1.5;
    ctx.setLineDash(marker.dashed ? [4, 3] : []);
    ctx.beginPath();
    ctx.moveTo(area.left, y);
    ctx.lineTo(area.right, y);
    ctx.stroke();

    // At the left end the bars are the fastest, below the median, so the label has room; the right end if that is taken
    const left = area.left + 4;
    const align = overlaps(labelBox(ctx, marker.label, left, y - 3, 'left'), occupied) ? 'right' : 'left';
    drawLabel(ctx, marker, align === 'left' ? left : area.right - 4, y - 3, align, occupied);
}

const markersPlugin = {
    id: 'leaderboardMarkers',

    afterDatasetsDraw(chart) {
        const config = chart.config.options?.plugins?.leaderboardMarkers;

        if (!config || !Array.isArray(config.markers) || config.markers.length === 0) {
            return;
        }

        const ctx = chart.ctx;
        const fontFamily = chart.options.font && chart.options.font.family ? chart.options.font.family : 'sans-serif';
        const occupied = [];

        ctx.save();
        ctx.font = `600 ${LABEL_FONT_SIZE}px ${fontFamily}`;
        ctx.textBaseline = 'bottom';

        drawVerticalMarkers(chart, config.markers.filter((marker) => (marker.kind || 'vertical') === 'vertical'), occupied);
        config.markers.filter((marker) => marker.kind === 'bar').forEach((marker) => drawBarLabel(chart, marker, occupied));
        config.markers.filter((marker) => marker.kind === 'horizontal').forEach((marker) => drawHorizontalMarker(chart, marker, occupied));

        ctx.restore();
    },
};

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

        config.plugins = [...(config.plugins || []), markersPlugin];
        this._applyOptions(config.options);
    }

    // A Live Component re-render replaces the options with plain JSON again
    _onViewValueChange(event) {
        this._applyOptions(event.detail.options);
    }

    _applyOptions(options) {
        const markers = options.plugins ? options.plugins.leaderboardMarkers : null;

        // Only the distribution has bar ranges; the bar-per-row chart's tooltips belong to the time-chart controller
        if (!markers || !Array.isArray(markers.ranges)) {
            return;
        }

        options.plugins.tooltip = options.plugins.tooltip || {};
        options.plugins.tooltip.callbacks = {
            ...(options.plugins.tooltip.callbacks || {}),
            title(items) {
                if (items.length === 0) {
                    return '';
                }

                const ranges = items[0].chart.config.options?.plugins?.leaderboardMarkers?.ranges;

                return ranges && ranges[items[0].dataIndex] ? ranges[items[0].dataIndex] : items[0].label;
            },
        };
    }
}
