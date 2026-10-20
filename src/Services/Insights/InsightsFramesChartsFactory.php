<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use SpeedPuzzling\Web\Results\Insights\ImprovementCurveSize;
use SpeedPuzzling\Web\Results\Insights\RatingForecastResult;
use SpeedPuzzling\Web\Results\Insights\RatingOverTimeSeries;
use SpeedPuzzling\Web\Results\Insights\ResolveCandidate;
use SpeedPuzzling\Web\Results\Insights\ResolveRadarResult;
use SpeedPuzzling\Web\Services\EventsPage\EventsPageDates;
use SpeedPuzzling\Web\Services\PredictionWindowFormatter;
use SpeedPuzzling\Web\Services\PuzzlingTimeFormatter;
use SpeedPuzzling\Web\Value\RatingForecastScenario;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

/**
 * The Chart.js charts of the lazy Insights parts (InsightsFrames) - like InsightsChartsFactory: plain Chart.js JSON
 * plus the options of assets/controllers/comparison_chart_controller.js (`plugins.comparisonChart`: tooltips and tick
 * labels written here, the end labels of the lines). Pure: every number comes from the built results. Members-only
 * charts only - the frames never build one for a free player.
 */
readonly final class InsightsFramesChartsFactory
{
    private const string INK = '#4b566b';
    private const string INK_MUTED = '#6b7488';
    private const string GRID = '#edf1f5';
    private const string TOOLTIP_BACKGROUND = 'rgba(55, 63, 80, 0.95)';
    private const string WHITE = '#ffffff';

    public function __construct(
        private ChartBuilderInterface $chartBuilder,
        private TranslatorInterface $translator,
        private PuzzlingTimeFormatter $timeFormatter,
        /** Locale-aware dates from ICU skeletons (the events pages' helper) */
        private EventsPageDates $dates,
        /** Prediction windows exactly as every other surface shows them (stream 3c) */
        private PredictionWindowFormatter $windowFormatter,
    ) {
    }

    /**
     * The rating month by month in the scenarios - "best moves" only while time predictions are on (the moves are
     * worth what their expected times give)
     */
    public function forecast(RatingForecastResult $forecast, bool $predictionsAllowed): null|Chart
    {
        $styles = [
            RatingForecastScenario::BestMoves->value => [InsightsChartsFactory::COLOR_YOU, []],
            RatingForecastScenario::KeepPace->value => [InsightsChartsFactory::COLOR_REF, []],
            RatingForecastScenario::Stop->value => [InsightsChartsFactory::COLOR_NEUTRAL, [5, 4]],
        ];
        $lines = [];

        foreach ($forecast->lines as $line) {
            if ($line->scenario === RatingForecastScenario::BestMoves && $predictionsAllowed === false) {
                continue;
            }

            $lines[$line->scenario->value] = $line;
        }

        if ($lines === []) {
            return null;
        }

        $first = reset($lines);
        $labels = [];

        foreach ($first->points as $point) {
            $labels[] = $point->monthsAhead === 0 ? $this->trans('frames.chart.now') : $this->dates->format($point->date, 'MMM');
        }

        $datasets = [];
        $tooltips = [];
        $endLabels = [];
        $values = [];

        foreach ($styles as $scenario => [$color, $dash]) {
            $line = $lines[$scenario] ?? null;

            if ($line === null) {
                continue;
            }

            $data = [];
            $lineTooltips = [];
            $name = $this->trans('forecast.scenario.' . $scenario);

            foreach ($line->points as $point) {
                $rating = (int) round($point->rating);
                $data[] = $rating;
                $values[] = $rating;
                $lineTooltips[] = [
                    'title' => $name,
                    'lines' => [$this->trans('frames.chart.forecast_point', [
                        '%when%' => $point->monthsAhead === 0 ? $this->trans('frames.chart.today') : $this->dates->format($point->date, 'yMMMM'),
                        '%rating%' => $rating,
                    ])],
                ];
            }

            $endLabels[] = ['datasetIndex' => count($datasets), 'text' => (string) $data[count($data) - 1]];
            $datasets[] = [
                'data' => $data,
                'borderColor' => $color,
                'backgroundColor' => $color,
                'borderWidth' => 2,
                'borderDash' => $dash,
                'pointRadius' => 3,
                'pointHoverRadius' => 5,
            ];
            $tooltips[] = $lineTooltips;
        }

        if ($values === []) {
            return null;
        }

        [$min, $max, $ticks] = self::niceTicks((int) floor(min($values) - 8), (int) ceil(max($values) + 6), 4);

        $chart = $this->chartBuilder->createChart(Chart::TYPE_LINE);
        $chart->setData(['labels' => $labels, 'datasets' => $datasets]);
        $chart->setOptions($this->options([
            'layout' => ['padding' => ['right' => 36]],
            'scales' => [
                'x' => $this->categoryAxis(),
                'y' => $this->linearAxis($min, $max),
            ],
        ], [
            'tooltips' => $tooltips,
            'ticks' => ['y' => array_map(static fn (int $tick): array => [$tick, (string) $tick], $ticks)],
            'endLabels' => $endLabels,
        ]));

        return $chart;
    }

    /**
     * Months since the last solve against the likely gain - filled dots on the shelf, bigger dots = more solvers
     */
    public function resolve(ResolveRadarResult $radar): null|Chart
    {
        if ($radar->candidates === []) {
            return null;
        }

        $sets = ['shelf' => [], 'other' => []];

        foreach ($radar->candidates as $candidate) {
            $sets[$candidate->onShelf ? 'shelf' : 'other'][] = $candidate;
        }

        $datasets = [];
        $tooltips = [];
        $maxMonths = 36.0;
        $maxGain = 0.3;

        foreach ($radar->candidates as $candidate) {
            $maxMonths = max($maxMonths, $candidate->monthsSinceLastSolve);
            $maxGain = max($maxGain, $candidate->likelyGain);
        }

        foreach ($sets as $set => $candidates) {
            if ($candidates === []) {
                continue;
            }

            $datasets[] = [
                'data' => array_map(static fn (ResolveCandidate $candidate): array => [
                    'x' => round($candidate->monthsSinceLastSolve, 1),
                    'y' => round($candidate->likelyGain * 100, 1),
                ], $candidates),
                'pointRadius' => array_map(static fn (ResolveCandidate $candidate): float => round(min(11.0, 3.0 + sqrt(max(0, $candidate->soloResultsCount)) / 3.0), 1), $candidates),
                'pointHoverRadius' => array_map(static fn (ResolveCandidate $candidate): float => round(min(13.0, 5.0 + sqrt(max(0, $candidate->soloResultsCount)) / 3.0), 1), $candidates),
                'backgroundColor' => $set === 'shelf' ? 'rgba(232, 72, 75, 0.8)' : self::WHITE,
                'borderColor' => InsightsChartsFactory::COLOR_YOU,
                'borderWidth' => 1.5,
            ];
            $tooltips[] = array_map(fn (ResolveCandidate $candidate): array => [
                'title' => $candidate->puzzle->name,
                'lines' => array_values(array_filter([
                    $this->trans('frames.chart.resolve_times', [
                        '%best%' => $this->timeFormatter->formatTime($candidate->bestSeconds),
                        '%window%' => $this->translator->trans('puzzle_intelligence.prediction.window.likely', ['%range%' => $this->windowFormatter->range($candidate->window())]),
                        '%percent%' => round($candidate->likelyGain * 100),
                    ]),
                    $this->trans('frames.chart.resolve_last', ['%count%' => (int) round($candidate->monthsSinceLastSolve)]),
                    $candidate->onShelf ? $this->trans('resolve.on_shelf') : null,
                ])),
            ], $candidates);
        }

        $xMax = (int) (ceil($maxMonths / 6) * 6);
        $xTicks = [];

        for ($month = 12; $month <= $xMax; $month += $xMax > 48 ? 12 : 6) {
            $xTicks[] = [$month, $this->trans('frames.chart.months', ['%count%' => $month])];
        }

        [$yMin, $yMax, $yTicks] = self::niceTicks(0, (int) ceil($maxGain * 108), 4);

        $chart = $this->chartBuilder->createChart(Chart::TYPE_SCATTER);
        $chart->setData(['datasets' => $datasets]);
        $chart->setOptions($this->options([
            'scales' => [
                'x' => $this->linearAxis(12, $xMax),
                'y' => $this->linearAxis($yMin, $yMax),
            ],
        ], [
            'tooltips' => $tooltips,
            'ticks' => [
                'x' => $xTicks,
                'y' => array_map(fn (int $tick): array => [$tick, $this->trans('percent', ['%value%' => $tick === 0 ? '0' : '−' . $tick])], $yTicks),
            ],
        ]));

        return $chart;
    }

    /**
     * The pace at one piece count by first tries - lower is faster - with the typical newcomer's curve (500 pieces)
     */
    public function improvement(ImprovementCurveSize $size): null|Chart
    {
        if (count($size->points) < 2) {
            return null;
        }

        $you = [];
        $youTooltips = [];
        $values = [];

        foreach ($size->points as $point) {
            $you[] = ['x' => $point->firstTries, 'y' => $point->paceSeconds];
            $values[] = $point->paceSeconds;
            $youTooltips[] = [
                'title' => $this->dates->format($point->date, 'yMMMd'),
                'lines' => [
                    $this->trans('frames.chart.pace', ['%pace%' => $this->timeFormatter->formatTime($point->paceSeconds)]),
                    $this->trans('frames.chart.first_tries', ['%count%' => $point->firstTries]),
                ],
            ];
        }

        $datasets = [[
            'data' => $you,
            'borderColor' => InsightsChartsFactory::COLOR_YOU,
            'backgroundColor' => InsightsChartsFactory::COLOR_YOU,
            'borderWidth' => 2,
            'pointRadius' => 0,
            'pointHoverRadius' => 4,
            'pointHitRadius' => 6,
            'stepped' => 'before',
        ]];
        $tooltips = [$youTooltips];

        if (count($size->typicalCurve) >= 2) {
            $typical = [];
            $typicalTooltips = [];

            foreach ($size->typicalCurve as $point) {
                $typical[] = ['x' => $point->firstTries, 'y' => $point->paceSeconds];
                $values[] = $point->paceSeconds;
                $typicalTooltips[] = [
                    'title' => $this->trans('improvement.legend.typical'),
                    'lines' => [
                        $this->trans('frames.chart.pace', ['%pace%' => $this->timeFormatter->formatTime($point->paceSeconds)]),
                        $this->trans('frames.chart.first_tries', ['%count%' => $point->firstTries]),
                    ],
                ];
            }

            $datasets[] = [
                'data' => $typical,
                'borderColor' => InsightsChartsFactory::COLOR_REF,
                'backgroundColor' => InsightsChartsFactory::COLOR_REF,
                'borderWidth' => 2,
                'borderDash' => [5, 4],
                'pointRadius' => 3,
            ];
            $tooltips[] = $typicalTooltips;
        }

        $step = self::timeStep(max($values) - min($values));
        $min = (int) (floor(min($values) / $step) * $step);
        $max = (int) (ceil(max($values) / $step) * $step);

        if ($max === $min) {
            $max += $step;
        }

        $yTicks = [];

        for ($value = $min; $value <= $max; $value += $step) {
            $yTicks[] = [$value, $this->timeFormatter->compactTime($value)];
        }

        $first = $size->points[0]->firstTries;
        $last = $size->points[count($size->points) - 1]->firstTries;

        $chart = $this->chartBuilder->createChart(Chart::TYPE_LINE);
        $chart->setData(['datasets' => $datasets]);
        $chart->setOptions($this->options([
            'scales' => [
                'x' => $this->linearAxis($first, max($last, $first + 1)),
                'y' => $this->linearAxis($min, $max),
            ],
        ], [
            'tooltips' => $tooltips,
            'ticks' => ['y' => $yTicks],
        ]));

        return $chart;
    }

    /**
     * The daily rating (player_rating_snapshot) within the period - the rating only; the place day by day is the MSP
     * Rating card's (RatingHistoryChartFactory, docs/features/msp-rating-history.md)
     */
    public function ratingOverTime(RatingOverTimeSeries $series): null|Chart
    {
        if (count($series->days) < 2) {
            return null;
        }

        $labels = [];
        $data = [];
        $tooltips = [];

        foreach ($series->days as $day) {
            $rating = (int) round($day->rating);
            $labels[] = $this->dates->format($day->date, 'MMMd');
            $data[] = $rating;
            $lines = [$this->trans('frames.chart.rating', ['%rating%' => $rating])];

            if ($day->change !== null && abs($day->change) >= 0.5) {
                $lines[] = $this->trans('frames.chart.rating_change', ['%change%' => sprintf('%+d', (int) round($day->change))]);
            }

            $tooltips[] = ['title' => $this->dates->format($day->date, 'yMMMd'), 'lines' => $lines];
        }

        [$min, $max, $ticks] = self::niceTicks((int) floor(min($data) - 5), (int) ceil(max($data) + 5), 4);

        $chart = $this->chartBuilder->createChart(Chart::TYPE_LINE);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [[
                'data' => $data,
                'borderColor' => InsightsChartsFactory::COLOR_REF,
                'backgroundColor' => 'rgba(78, 84, 200, 0.12)',
                'fill' => true,
                'borderWidth' => 2,
                'pointRadius' => 0,
                'pointHoverRadius' => 4,
                'pointHitRadius' => 6,
            ]],
        ]);
        $chart->setOptions($this->options([
            'scales' => [
                'x' => array_replace_recursive($this->categoryAxis(), ['ticks' => ['autoSkip' => true, 'autoSkipPadding' => 12]]),
                'y' => $this->linearAxis($min, $max),
            ],
        ], [
            'tooltips' => [$tooltips],
            'ticks' => ['y' => array_map(static fn (int $tick): array => [$tick, (string) $tick], $ticks)],
        ]));

        return $chart;
    }

    /**
     * Round ticks covering [$low, $high]: about $count steps of 1, 2, 5 × 10^n
     *
     * @return array{0: int, 1: int, 2: list<int>}
     */
    private static function niceTicks(int $low, int $high, int $count): array
    {
        if ($high <= $low) {
            $high = $low + 1;
        }

        $raw = ($high - $low) / $count;
        $magnitude = 10 ** floor(log10($raw));
        $step = 1;

        foreach ([1, 2, 5, 10] as $factor) {
            $step = (int) max(1, $factor * $magnitude);

            if ($factor * $magnitude >= $raw) {
                break;
            }
        }

        $min = (int) (floor($low / $step) * $step);
        $max = (int) (ceil($high / $step) * $step);
        $ticks = [];

        for ($value = $min; $value <= $max; $value += $step) {
            $ticks[] = $value;
        }

        return [$min, $max, $ticks];
    }

    /**
     * A tick step in seconds for a span of paces: 1, 2, 5, 10, 15, 30 minutes, 1 or 2 hours
     */
    private static function timeStep(int $span): int
    {
        foreach ([60, 120, 300, 600, 900, 1800, 3600] as $step) {
            if ($span / $step <= 5) {
                return $step;
            }
        }

        return 7200;
    }


    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $plugin
     * @return array<string, mixed>
     */
    private function options(array $options, array $plugin): array
    {
        $options['maintainAspectRatio'] = false;
        $options['animation'] = false;
        $options['plugins'] = [
            // Legends are HTML under the charts
            'legend' => ['display' => false],
            'tooltip' => [
                'backgroundColor' => self::TOOLTIP_BACKGROUND,
                'padding' => 8,
                'boxPadding' => 4,
                'displayColors' => false,
            ],
            'comparisonChart' => $plugin + ['labelColor' => self::INK],
        ];

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    private function categoryAxis(): array
    {
        return [
            'grid' => ['display' => false],
            'border' => ['color' => self::GRID],
            'ticks' => ['color' => self::INK_MUTED, 'font' => ['size' => 11], 'maxRotation' => 0, 'autoSkip' => false],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function linearAxis(int $min, int $max): array
    {
        return [
            'type' => 'linear',
            'min' => $min,
            'max' => $max,
            'grid' => ['color' => self::GRID, 'drawTicks' => false],
            'border' => ['display' => false],
            'ticks' => ['color' => self::INK_MUTED, 'font' => ['size' => 11], 'padding' => 6],
        ];
    }

    /**
     * @param array<string, int|float|string> $parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans('insights.' . $key, $parameters);
    }
}
