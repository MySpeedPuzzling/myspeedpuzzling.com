<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component\Chart;

use Nette\Utils\Strings;
use SpeedPuzzling\Web\Results\LeaderboardHistogram;
use SpeedPuzzling\Web\Results\LeaderboardHistogramBin;
use SpeedPuzzling\Web\Results\PuzzleSolver;
use SpeedPuzzling\Web\Results\PuzzleSolversGroup;
use SpeedPuzzling\Web\Services\LeaderboardHistogramBuilder;
use SpeedPuzzling\Web\Services\PuzzlingTimeFormatter;
use SpeedPuzzling\Web\Value\LeaderboardChartView;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Members' chart above the puzzle leaderboard (docs/features/puzzle-leaderboard-chart.md): the distribution of the
 * times, or the ranking - one bar per row - when the member switched to it. Either way it is drawn from the whole
 * filtered leaderboard, never from the rows the table shows.
 */
#[AsTwigComponent]
final class PuzzleTimesChart
{
    private const string COLOR_VIEWER = 'rgba(254, 64, 66, 1)';
    // The outline of the viewer's bar in the distribution: a lighter tone of the You line (Jan, 2026-09-30)
    private const string COLOR_VIEWER_OUTLINE = 'rgba(254, 110, 112, 1)';
    private const string COLOR_FIRST_ATTEMPT = 'rgba(105, 179, 254, 0.6)';
    private const string COLOR_FIRST_ATTEMPT_TAIL = 'rgba(105, 179, 254, 0.3)';
    private const string COLOR_OTHER = 'rgba(254, 105, 106, 0.6)';
    private const string COLOR_FOLDED_TAIL = 'rgba(254, 105, 106, 0.3)';
    private const string COLOR_MEDIAN = '#4b566b';

    public null|string $playerId = null;

    /**
     * @var array<array<PuzzleSolver|PuzzleSolversGroup>>
     */
    public array $results = [];

    // solo, duo or group - what one row of the leaderboard is
    public string $category = 'solo';

    public LeaderboardChartView $view = LeaderboardChartView::Distribution;

    private null|LeaderboardHistogram $histogram = null;

    public function __construct(
        readonly private ChartBuilderInterface $chartBuilder,
        readonly private LeaderboardHistogramBuilder $histogramBuilder,
        readonly private PuzzlingTimeFormatter $timeFormatter,
        readonly private TranslatorInterface $translator,
    ) {
    }

    public function isDistribution(): bool
    {
        return $this->view === LeaderboardChartView::Distribution;
    }

    public function getChart(): Chart
    {
        return $this->isDistribution() ? $this->distributionChart() : $this->individualChart();
    }

    /**
     * What the distribution chart shows, for screen readers
     */
    public function getSummary(): string
    {
        $histogram = $this->histogram();

        $summary = $this->translator->trans('puzzle_times.chart.summary.' . $this->categoryKey(), [
            '%count%' => $histogram->total,
            '%median%' => $this->timeFormatter->formatTime($histogram->medianTime ?? 0),
        ]);

        if ($histogram->viewerTime !== null) {
            $summary .= ' ' . $this->translator->trans('puzzle_times.chart.summary_you', [
                '%time%' => $this->timeFormatter->formatTime($histogram->viewerTime),
            ]);
        }

        return $summary;
    }

    /**
     * The distribution's two colours, for the legend under it - none when the bars show only one of them, e.g. with
     * "1st tries only" every bar is blue
     *
     * @return list<array{label: string, color: string}>
     */
    public function getLegend(): array
    {
        $firstAttempts = 0;
        $repeats = 0;

        foreach ($this->histogram()->bins as $bin) {
            $firstAttempts += $bin->firstAttempts;
            $repeats += $bin->repeats();
        }

        if ($firstAttempts === 0 || $repeats === 0) {
            return [];
        }

        return [
            ['label' => $this->translator->trans('first_attempt'), 'color' => self::COLOR_FIRST_ATTEMPT],
            ['label' => $this->translator->trans('puzzle_times.chart.legend_repeat'), 'color' => self::COLOR_OTHER],
        ];
    }

    private function distributionChart(): Chart
    {
        $histogram = $this->histogram();
        $labels = [];
        $ranges = [];
        $tooltips = [];
        $firstAttempts = [];
        $repeats = [];
        $firstAttemptColors = [];
        $repeatColors = [];

        // Each bar splits into first attempts (blue, like the bar-per-row chart) and repeats; the folded tails are lighter
        foreach ($histogram->bins as $bin) {
            [$label, $range] = $this->binLabels($bin);
            $labels[] = $label;
            $ranges[] = $range;
            $tooltips[] = $this->binTooltip($bin);
            $firstAttempts[] = $bin->firstAttempts;
            $repeats[] = $bin->repeats();
            $firstAttemptColors[] = $bin->isFoldedTail() ? self::COLOR_FIRST_ATTEMPT_TAIL : self::COLOR_FIRST_ATTEMPT;
            $repeatColors[] = $bin->isFoldedTail() ? self::COLOR_FOLDED_TAIL : self::COLOR_OTHER;
        }

        // Drawn by the leaderboard-chart Stimulus controller, positioned in bar units
        $markers = [];

        if ($histogram->medianPosition !== null) {
            $markers[] = [
                'kind' => 'vertical',
                'position' => $histogram->medianPosition,
                'label' => $this->translator->trans('puzzle_times.chart.median'),
                'color' => self::COLOR_MEDIAN,
                'dashed' => true,
            ];
        }

        if ($histogram->viewerPosition !== null) {
            $markers[] = [
                'kind' => 'vertical',
                'position' => $histogram->viewerPosition,
                'label' => $this->translator->trans('puzzle_times.chart.you'),
                'color' => self::COLOR_VIEWER,
                'dashed' => false,
            ];
        }

        $noun = $this->translator->trans('puzzle_times.chart.noun.' . $this->categoryKey());
        $bar = ['barPercentage' => 1.0, 'categoryPercentage' => 0.92, 'stack' => 'rows'];

        $chart = $this->chartBuilder->createChart(Chart::TYPE_BAR);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [
                ['label' => $this->translator->trans('first_attempt'), 'data' => $firstAttempts, 'backgroundColor' => $firstAttemptColors] + $bar,
                ['label' => $this->translator->trans('puzzle_times.chart.legend_repeat'), 'data' => $repeats, 'backgroundColor' => $repeatColors] + $bar,
            ],
        ]);

        $chart->setOptions([
            'maintainAspectRatio' => false,
            'layout' => [
                // Room for the marker labels above the bars
                'padding' => ['top' => 18],
            ],
            // One tooltip per bar, wherever on it the pointer is
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'scales' => [
                'x' => [
                    'stacked' => true,
                    'grid' => ['display' => false],
                    'ticks' => ['maxRotation' => 0, 'autoSkipPadding' => 12],
                ],
                'y' => [
                    'stacked' => true,
                    'beginAtZero' => true,
                    'ticks' => ['precision' => 0],
                    'title' => ['display' => true, 'text' => $noun],
                ],
            ],
            'plugins' => [
                // The legend is HTML under the chart
                'legend' => ['display' => false],
                'leaderboardMarkers' => [
                    'markers' => $markers,
                    'ranges' => $ranges,
                    'tooltips' => $tooltips,
                    // The viewer's bar is outlined, not filled, so its split stays readable
                    'highlight' => $histogram->viewerBin !== null
                        ? ['index' => $histogram->viewerBin, 'color' => self::COLOR_VIEWER_OUTLINE]
                        : null,
                ],
            ],
        ]);

        return $chart;
    }

    /**
     * "132 puzzlers · 97 first tries · 35 repeats" - a part that would be 0 is left out
     */
    private function binTooltip(LeaderboardHistogramBin $bin): string
    {
        $parts = [$this->translator->trans('puzzle_times.chart.count.' . $this->categoryKey(), ['%count%' => $bin->count])];

        if ($bin->firstAttempts > 0) {
            $parts[] = $this->translator->trans('puzzle_times.chart.first_attempts_count', ['%count%' => $bin->firstAttempts]);
        }

        if ($bin->repeats() > 0) {
            $parts[] = $this->translator->trans('puzzle_times.chart.repeats_count', ['%count%' => $bin->repeats()]);
        }

        return implode(' · ', $parts);
    }

    private function individualChart(): Chart
    {
        $labels = [];
        $chartData = [];
        $backgrounds = [];
        $viewerIndex = null;

        $rank = 0;
        $i = 0;
        $lastKey = null;
        foreach ($this->results as $key => $groupedResult) {
            $i++;
            $result = $groupedResult[0];

            if ($lastKey === null || $result->time !== $this->results[$lastKey][0]->time) {
                $rank = $i;
            }

            if ($viewerIndex === null && $this->isViewer($result)) {
                $viewerIndex = $i - 1;
            }

            if ($result instanceof PuzzleSolver) {
                $labels[] = sprintf(
                    '%d. %s',
                    $rank,
                    Strings::truncate($result->playerName ?? $result->playerCode, 15),
                );

                if ($this->isViewer($result)) {
                    $backgrounds[] = self::COLOR_VIEWER;
                } elseif ($result->firstAttempt === true) {
                    $backgrounds[] = self::COLOR_FIRST_ATTEMPT;
                } else {
                    $backgrounds[] = self::COLOR_OTHER;
                }
            }

            if ($result instanceof PuzzleSolversGroup) {
                $label = [];

                foreach ($result->players as $player) {
                    $label[] = Strings::truncate($player->playerName ?? $player->playerCode ?? '', 15);
                }

                $labels[] = implode("\n", $label);

                if ($this->isViewer($result)) {
                    $backgrounds[] = self::COLOR_VIEWER;
                } elseif ($result->firstAttempt === true) {
                    $backgrounds[] = self::COLOR_FIRST_ATTEMPT;
                } else {
                    $backgrounds[] = self::COLOR_OTHER;
                }
            }

            $chartData[] = $result->time;
            $lastKey = $key;
        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_BAR);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [
                [
                    'backgroundColor' => $backgrounds,
                    'data' => $chartData,
                ],
            ],
        ]);

        // Drawn by the leaderboard-chart Stimulus controller: the median across the bars (y is time here) and "You"
        // above the viewer's bar, which the rows' order makes the first of theirs
        $histogram = $this->histogram();
        $markers = [];

        if ($histogram->medianTime !== null) {
            $markers[] = [
                'kind' => 'horizontal',
                'value' => $histogram->medianTime,
                'label' => $this->translator->trans('puzzle_times.chart.median'),
                'color' => self::COLOR_MEDIAN,
                'dashed' => true,
            ];
        }

        if ($viewerIndex !== null) {
            $markers[] = [
                'kind' => 'bar',
                'index' => $viewerIndex,
                'label' => $this->translator->trans('puzzle_times.chart.you'),
                'color' => self::COLOR_VIEWER,
            ];
        }

        $chart->setOptions([
            'layout' => [
                // Room for "You" above the tallest bar
                'padding' => ['top' => 18],
            ],
            'scales' => [
                'x' => [
                    'grid' => [
                        'display' => false,
                    ],
                    'ticks' => [
                        'display' => false,
                    ],
                ],
            ],
            'plugins' => [
                'legend' => [
                    'display' => false,
                ],
                'leaderboardMarkers' => [
                    'markers' => $markers,
                ],
            ],
        ]);

        return $chart;
    }

    private function histogram(): LeaderboardHistogram
    {
        if ($this->histogram !== null) {
            return $this->histogram;
        }

        $times = [];
        $firstAttemptTimes = [];
        $viewerTime = null;

        foreach ($this->results as $groupedResult) {
            $result = $groupedResult[0];

            if ($result->time === null) {
                continue;
            }

            $times[] = $result->time;

            // The row's shown time decides, as it does for the blue bars of the bar-per-row chart
            if ($result->firstAttempt === true) {
                $firstAttemptTimes[] = $result->time;
            }

            // Rows are sorted by time: the first one of the viewer's is their best
            if ($viewerTime === null && $this->isViewer($result)) {
                $viewerTime = $result->time;
            }
        }

        return $this->histogram = $this->histogramBuilder->build($times, $viewerTime, $firstAttemptTimes);
    }

    private function isViewer(PuzzleSolver|PuzzleSolversGroup $result): bool
    {
        if ($this->playerId === null || $this->playerId === '') {
            return false;
        }

        if ($result instanceof PuzzleSolver) {
            return $result->playerId === $this->playerId;
        }

        return $result->containsPlayer($this->playerId);
    }

    /**
     * @return array{string, string} the axis label and the tooltip title
     */
    private function binLabels(LeaderboardHistogramBin $bin): array
    {
        if ($bin->to === null) {
            $label = '≥ ' . $this->timeFormatter->formatTime($bin->from ?? 0);

            return [$label, $label];
        }

        if ($bin->from === null) {
            $label = '< ' . $this->timeFormatter->formatTime($bin->to);

            return [$label, $label];
        }

        $from = $this->timeFormatter->formatTime($bin->from);

        return [$from, $from . ' – ' . $this->timeFormatter->formatTime($bin->to)];
    }

    private function categoryKey(): string
    {
        return in_array($this->category, ['duo', 'group'], true) ? $this->category : 'solo';
    }
}
