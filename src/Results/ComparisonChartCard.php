<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\DifficultyTier;
use Symfony\UX\Chartjs\Model\Chart;

/**
 * One card on the Charts tab of the compare page (ComparisonChartsFactory): title, the takeaway line, then either the
 * chart (Chart.js, or the head-to-head grid as an HTML table) or - when the data is too thin - a short note instead.
 * Every text is translated server-side; the Stimulus controller only places them.
 */
readonly final class ComparisonChartCard
{
    public const string LEAD_LAG = 'lead_lag';
    public const string SCATTER = 'scatter';
    public const string PACE = 'pace';
    public const string DIFFICULTY = 'difficulty';
    public const string FORM = 'form';
    public const string MATRIX = 'matrix';

    /**
     * @param list<array{label: string, color: string, shape: 'bar'|'dot'|'line'|'dash'}> $legend
     * @param null|array{
     *     columns: list<array{abbr: string, name: string}>,
     *     rows: list<array{name: string, role: 'a'|'b'|'other', cells: list<array{diagonal: bool, wins: null|int, step: null|int, title: string}>}>,
     *     ramp: list<string>,
     *     rampLow: string,
     *     rampHigh: string,
     * } $grid the head-to-head table (MATRIX only): `step` 1-5 is the ramp colour of a cell, null = nothing in common
     *   (blank); its legend is the ramp between rampLow and rampHigh
     * @param null|array{
     *     title: string,
     *     caption: string,
     *     rows: list<array{tier: DifficultyTier, label: string, a: int, b: int, ties: int, shared: int, text: string}>,
     * } $wins the highlighted pair's head to head per difficulty tier under the chart (DIFFICULTY only, when the two share a
     *   rated puzzle): `a` / `b` / `ties` split the `shared` puzzles, `text` is the whole row as a sentence
     */
    public function __construct(
        public string $key,
        public string $title,
        public null|string $takeaway,
        // What the chart shows, for screen readers (aria-label of the canvas, caption of the grid)
        public string $summary,
        public null|Chart $chart = null,
        public int $height = 240,
        public array $legend = [],
        // A muted line after the legend, e.g. "20 of 41 puzzles shown"
        public null|string $legendNote = null,
        // Captions under the chart's start and end ("← You faster" / "Kateřina faster →")
        public null|string $axisStart = null,
        public null|string $axisEnd = null,
        // Too little data: the note is shown instead of the chart
        public null|string $note = null,
        public null|array $grid = null,
        public null|array $wins = null,
    ) {
    }

    public function isShown(): bool
    {
        return $this->note === null;
    }
}
