<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use SpeedPuzzling\Web\Results\Insights\InsightsLearningPoint;
use SpeedPuzzling\Web\Results\Insights\InsightsReport;
use SpeedPuzzling\Web\Services\PuzzlingTimeFormatter;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

/**
 * The Chart.js charts of the Insights page from a built report - plain Chart.js JSON plus the options of
 * assets/controllers/comparison_chart_controller.js (`plugins.comparisonChart`: tooltips written here, exactly these
 * ticks with these labels, a darker zero line). Pure: the report holds every number, nothing is computed again.
 *
 * Members-only charts (expected vs actual, the learning curve) are built only for members, and the weekday chart's
 * pace lines too: a free player's page never carries their data.
 */
readonly final class InsightsChartsFactory
{
    public const string COLOR_YOU = '#e8484b';
    public const string COLOR_REF = '#4e54c8';
    public const string COLOR_FASTER = '#13a07a';
    public const string COLOR_SLOWER = '#d4602a';
    public const string COLOR_NEUTRAL = '#aeb4be';

    private const string INK = '#4b566b';
    private const string INK_MUTED = '#6b7488';
    private const string GRID = '#edf1f5';
    private const string ZERO_LINE = '#aeb4be';
    private const string TOOLTIP_BACKGROUND = 'rgba(55, 63, 80, 0.95)';

    /** The ticks of expected vs actual's log axes, in minutes */
    private const array MINUTE_TICKS = [1, 2, 5, 10, 15, 20, 30, 45, 60, 90, 120, 180, 240, 360, 480, 720];

    public function __construct(
        private ChartBuilderInterface $chartBuilder,
        private TranslatorInterface $translator,
        private PuzzlingTimeFormatter $timeFormatter,
    ) {
    }

    /**
     * @return array<string, Chart> profile, form, histogram, weekdays (+ expected, learning for members) - each only
     *                              when it has something to draw
     */
    public function charts(InsightsReport $report, bool $isMember): array
    {
        $charts = [
            'profile' => $this->profile($report),
            'form' => $this->form($report),
            'histogram' => $this->histogram($report),
            'weekdays' => $this->weekdays($report, $isMember),
        ];

        if ($isMember) {
            $charts['expected'] = $this->expected($report);
            $charts['learning'] = $this->learning($report);
        }

        return array_filter($charts, static fn (null|Chart $chart): bool => $chart !== null);
    }

    /**
     * The puzzler card's 7 axes as a radar, 50 = the middle of all puzzlers
     */
    private function profile(InsightsReport $report): null|Chart
    {
        $axes = $report->profileCard->axes;

        if ($report->profileCard->notSureYet || $axes === []) {
            return null;
        }

        $labels = [];
        $values = [];
        $tooltips = [];

        foreach ($axes as $axis) {
            $name = $this->trans('axis.' . $axis->axis->value . '.name');
            $labels[] = $name;
            $values[] = $axis->percentile !== null ? round($axis->percentile) : null;
            $tooltips[] = [
                'title' => $name,
                'lines' => [$axis->percentile !== null
                    ? $this->trans('chart.profile.better_than', ['%percent%' => round($axis->percentile)])
                    : $this->trans('chart.profile.not_enough')],
            ];
        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_RADAR);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [[
                'data' => $values,
                'backgroundColor' => 'rgba(232, 72, 75, 0.18)',
                'borderColor' => self::COLOR_YOU,
                'borderWidth' => 2,
                'pointBackgroundColor' => self::COLOR_YOU,
                'pointRadius' => 4,
                'spanGaps' => true,
            ]],
        ]);
        $chart->setOptions($this->options([
            'scales' => [
                'r' => [
                    'min' => 0,
                    'max' => 100,
                    'ticks' => ['stepSize' => 25, 'display' => false],
                    'grid' => ['color' => self::GRID],
                    'angleLines' => ['color' => self::GRID],
                    'pointLabels' => ['color' => self::INK, 'font' => ['size' => 12, 'weight' => '500']],
                ],
            ],
        ], ['tooltips' => [$tooltips]]));

        return $chart;
    }

    /**
     * Your monthly median against the median solver, the middle half of your results around it, and your usual -
     * up = faster (percent faster than the median solver)
     */
    private function form(InsightsReport $report): null|Chart
    {
        $form = $report->form;

        if ($form->notSureYet || $form->months === []) {
            return null;
        }

        $labels = [];
        $median = [];
        $high = [];
        $low = [];
        $usual = [];
        $tooltips = [];
        $values = [0.0];

        foreach ($form->months as $month) {
            $labels[] = $this->monthLabel($month->month);
            $median[] = self::fasterPercent($month->medianRatio);
            // The band: q25 of the ratio is the faster edge
            $high[] = self::fasterPercent($month->q25Ratio);
            $low[] = self::fasterPercent($month->q75Ratio);
            $usual[] = self::fasterPercent($month->usual);

            foreach ([$month->q25Ratio, $month->q75Ratio, $month->usual] as $ratio) {
                if ($ratio !== null) {
                    $values[] = (float) self::fasterPercent($ratio);
                }
            }

            $tooltips[] = $month->medianRatio === null
                ? null
                : [
                    'title' => $this->monthLabel($month->month, long: true),
                    'lines' => [
                        $this->versus($month->medianRatio, 'median'),
                        $this->trans('chart.results', ['%count%' => $month->count]),
                    ],
                ];
        }

        [$min, $max, $ticks] = $this->percentAxis($values, zeroLabel: $this->trans('chart.form.median_solver'));

        $chart = $this->chartBuilder->createChart(Chart::TYPE_LINE);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [
                ['data' => $median, 'borderColor' => self::COLOR_YOU, 'backgroundColor' => self::COLOR_YOU, 'borderWidth' => 2, 'pointRadius' => 3, 'spanGaps' => false, 'order' => 0],
                ['data' => $high, 'borderWidth' => 0, 'pointRadius' => 0, 'fill' => '+1', 'backgroundColor' => 'rgba(232, 72, 75, 0.14)', 'spanGaps' => false, 'order' => 2],
                ['data' => $low, 'borderWidth' => 0, 'pointRadius' => 0, 'spanGaps' => false, 'order' => 2],
                ['data' => $usual, 'borderColor' => self::COLOR_REF, 'borderWidth' => 2, 'borderDash' => [5, 4], 'pointRadius' => 0, 'spanGaps' => true, 'order' => 1],
            ],
        ]);
        $chart->setOptions($this->options([
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'scales' => [
                // Up to 60 months: Chart.js skips labels that would collide
                'x' => array_replace_recursive($this->categoryAxis(), ['ticks' => ['autoSkip' => true, 'autoSkipPadding' => 10]]),
                'y' => $this->linearAxis($min, $max),
            ],
        ], [
            'tooltips' => [$tooltips, [], [], []],
            'ticks' => ['y' => $ticks],
            'zeroLine' => ['axis' => 'y', 'color' => self::ZERO_LINE],
        ]));

        return $chart;
    }

    /**
     * Your results by how much faster (right) or slower (left) than each puzzle's median solver they were
     */
    private function histogram(InsightsReport $report): null|Chart
    {
        $histogram = $report->histogram;

        if ($histogram->notSureYet || $histogram->bins === []) {
            return null;
        }

        $labels = [];
        $counts = [];
        $colors = [];
        $tooltips = [];

        foreach ($histogram->bins as $bin) {
            $faster = $bin->fromPercent >= 0;
            $labels[] = $bin->fromPercent % 20 === 0 ? ($bin->fromPercent === 0 ? '0' : $this->trans('percent', ['%value%' => sprintf('%+d', $bin->fromPercent)])) : '';
            $counts[] = $bin->count;
            $colors[] = $faster ? self::COLOR_YOU : 'rgba(232, 72, 75, 0.45)';
            $tooltips[] = $bin->count === 0 ? null : [
                'title' => $faster
                    ? $this->trans('chart.histogram.faster', ['%from%' => $bin->fromPercent, '%to%' => $bin->toPercent])
                    : $this->trans('chart.histogram.slower', ['%from%' => -$bin->toPercent, '%to%' => -$bin->fromPercent]),
                'lines' => [$this->trans('chart.results', ['%count%' => $bin->count])],
            ];
        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_BAR);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [[
                'data' => $counts,
                'backgroundColor' => $colors,
                'borderRadius' => 3,
                'barPercentage' => 0.92,
                'categoryPercentage' => 1.0,
            ]],
        ]);
        $chart->setOptions($this->options([
            'scales' => [
                'x' => $this->categoryAxis() + ['title' => ['display' => true, 'text' => $this->trans('chart.histogram.axis'), 'color' => self::INK_MUTED, 'font' => ['size' => 11]]],
                'y' => ['beginAtZero' => true, 'grid' => ['color' => self::GRID], 'border' => ['display' => false], 'ticks' => ['color' => self::INK_MUTED, 'font' => ['size' => 11], 'precision' => 0]],
            ],
        ], ['tooltips' => [$tooltips]]));

        return $chart;
    }

    /**
     * Expected time against yours (members), both on a log scale - the diagonal = exactly as expected
     */
    private function expected(InsightsReport $report): null|Chart
    {
        $expected = $report->expectedVsActual;

        if ($report->predictionsOff || $expected->notSureYet || $expected->points === []) {
            return null;
        }

        $groups = ['better' => [], 'within' => [], 'worse' => []];
        $tooltips = ['better' => [], 'within' => [], 'worse' => []];
        $minMinutes = INF;
        $maxMinutes = 0.0;

        foreach ($expected->points as $point) {
            $group = match (true) {
                $point->deviation < -$expected::THRESHOLD => 'better',
                $point->deviation > $expected::THRESHOLD => 'worse',
                default => 'within',
            };
            $x = round($point->expectedSeconds / 60, 2);
            $y = round($point->actualSeconds / 60, 2);
            $groups[$group][] = ['x' => $x, 'y' => $y];
            $tooltips[$group][] = [
                'title' => $this->versus(1 + $point->deviation, 'expected'),
                'lines' => [
                    $this->trans('chart.expected.yours', ['%time%' => $this->timeFormatter->formatTime($point->actualSeconds)]),
                    $this->trans('chart.expected.expected', ['%time%' => $this->timeFormatter->formatTime($point->expectedSeconds)]),
                ],
            ];
            $minMinutes = min($minMinutes, $x, $y);
            $maxMinutes = max($maxMinutes, $x, $y);
        }

        $min = max(0.5, $minMinutes * 0.85);
        $max = $maxMinutes * 1.15;
        $ticks = [];

        foreach (self::MINUTE_TICKS as $minutes) {
            if ($minutes >= $min && $minutes <= $max) {
                $ticks[] = [$minutes, $minutes >= 60 && $minutes % 60 === 0 ? ($minutes / 60) . ' h' : $minutes . ' min'];
            }
        }

        $point = static fn (string $color): array => ['backgroundColor' => $color, 'borderColor' => '#ffffff', 'borderWidth' => 1, 'pointRadius' => 4, 'pointHoverRadius' => 6];

        $chart = $this->chartBuilder->createChart(Chart::TYPE_SCATTER);
        $chart->setData([
            'datasets' => [
                ['type' => 'line', 'data' => [['x' => $min, 'y' => $min], ['x' => $max, 'y' => $max]], 'borderColor' => self::COLOR_NEUTRAL, 'borderDash' => [4, 4], 'borderWidth' => 1.5, 'pointRadius' => 0, 'pointHitRadius' => 0],
                ['data' => $groups['within']] + $point(self::COLOR_NEUTRAL),
                ['data' => $groups['better']] + $point(self::COLOR_FASTER),
                ['data' => $groups['worse']] + $point(self::COLOR_SLOWER),
            ],
        ]);
        $axis = fn (string $title): array => [
            'type' => 'logarithmic',
            'min' => $min,
            'max' => $max,
            'grid' => ['color' => self::GRID],
            'border' => ['display' => false],
            'title' => ['display' => true, 'text' => $title, 'color' => self::INK_MUTED, 'font' => ['size' => 11]],
            'ticks' => ['color' => self::INK_MUTED, 'font' => ['size' => 11], 'maxRotation' => 0],
        ];
        $chart->setOptions($this->options([
            'scales' => [
                'x' => $axis($this->trans('chart.expected.axis_x')),
                'y' => $axis($this->trans('chart.expected.axis_y')),
            ],
        ], [
            'tooltips' => [[], $tooltips['within'], $tooltips['better'], $tooltips['worse']],
            'ticks' => ['x' => $ticks, 'y' => $ticks],
        ]));

        return $chart;
    }

    /**
     * Your time on attempt N ÷ attempt 1, against everybody's (members)
     */
    private function learning(InsightsReport $report): null|Chart
    {
        $learning = $report->learningCurve;

        if ($learning->notSureYet) {
            return null;
        }

        $labels = [];

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $labels[] = $this->trans('chart.learning.attempt_' . $attempt);
        }

        $series = function (array $points) use (&$labels): array {
            $data = array_fill(0, 4, null);
            $tooltips = array_fill(0, 4, null);

            foreach ($points as $point) {
                assert($point instanceof InsightsLearningPoint);
                $index = $point->attempt - 1;

                if ($index < 0 || $index > 3 || $point->factor === null) {
                    continue;
                }

                $data[$index] = round((1 - $point->factor) * 100, 1);
                $tooltips[$index] = [
                    'title' => $labels[$index],
                    'lines' => [$point->attempt === 1
                        ? $this->trans('chart.learning.first')
                        : $this->trans('chart.learning.faster', ['%percent%' => round((1 - $point->factor) * 100)])],
                ];
            }

            return [$data, $tooltips];
        };

        [$you, $youTooltips] = $series($learning->you);
        [$community, $communityTooltips] = $series($learning->community);

        $values = array_filter([...$you, ...$community, 0.0], static fn (null|float $value): bool => $value !== null);
        $top = max(10, (int) (ceil(max($values) / 5) * 5));
        $ticks = [];

        for ($value = 0; $value <= $top; $value += $top > 20 ? 10 : 5) {
            $ticks[] = [$value, $value === 0 ? $this->trans('chart.learning.first_short') : $this->trans('percent', ['%value%' => '−' . $value])];
        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_LINE);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [
                ['data' => $you, 'borderColor' => self::COLOR_YOU, 'backgroundColor' => self::COLOR_YOU, 'borderWidth' => 2, 'pointRadius' => 4, 'spanGaps' => true],
                ['data' => $community, 'borderColor' => self::COLOR_REF, 'backgroundColor' => self::COLOR_REF, 'borderWidth' => 2, 'pointRadius' => 4, 'spanGaps' => true],
            ],
        ]);
        $chart->setOptions($this->options([
            'scales' => [
                'x' => $this->categoryAxis(),
                // Down = faster: the first attempt on top, like the prototype
                'y' => $this->linearAxis(0, $top) + ['reverse' => true],
            ],
        ], [
            'tooltips' => [$youTooltips, $communityTooltips],
            'ticks' => ['y' => $ticks],
        ]));

        return $chart;
    }

    /**
     * Results per weekday (free); members also read their pace per day in the tooltip
     */
    private function weekdays(InsightsReport $report, bool $isMember): null|Chart
    {
        $habits = $report->habits;

        if ($habits->notSureYet || $habits->weekdays === []) {
            return null;
        }

        $labels = [];
        $counts = [];
        $tooltips = [];

        foreach ($habits->weekdays as $bucket) {
            $labels[] = $this->trans('weekday_short.' . $bucket->key);
            $counts[] = $bucket->count;
            $lines = [$this->trans('chart.results', ['%count%' => $bucket->count])];

            if ($isMember && $bucket->stats->effect !== null) {
                $lines[] = $this->versus(1 + $bucket->stats->effect, 'usual');
            }

            $tooltips[] = ['title' => $this->trans('weekday.' . $bucket->key), 'lines' => $lines];
        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_BAR);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [[
                'data' => $counts,
                'backgroundColor' => self::COLOR_REF,
                'borderRadius' => 4,
                'barPercentage' => 0.6,
            ]],
        ]);
        $chart->setOptions($this->options([
            'scales' => [
                'x' => $this->categoryAxis(),
                'y' => ['beginAtZero' => true, 'grid' => ['color' => self::GRID], 'border' => ['display' => false], 'ticks' => ['color' => self::INK_MUTED, 'font' => ['size' => 11], 'precision' => 0]],
            ],
        ], ['tooltips' => [$tooltips]]));

        return $chart;
    }

    // --- Helpers -----------------------------------------------------------------------------------------------------

    /**
     * Percent faster than the yardstick: ratio 0.88 = +12
     */
    private static function fasterPercent(null|float $ratio): null|float
    {
        return $ratio === null ? null : round((1 - $ratio) * 100, 1);
    }

    private function versus(float $ratio, string $against): string
    {
        $percent = (int) round(abs(1 - $ratio) * 100);

        if ($percent < 1) {
            return $this->trans('versus.' . $against . '.level');
        }

        return $this->trans('versus.' . $against . '.' . ($ratio < 1 ? 'faster' : 'slower'), ['%percent%' => $percent]);
    }

    /**
     * A symmetric-enough percent axis around the values, in steps of 5 / 10 / 20, with labelled ticks
     *
     * @param non-empty-list<float> $values
     * @return array{0: int, 1: int, 2: list<array{0: int, 1: string}>}
     */
    private function percentAxis(array $values, string $zeroLabel): array
    {
        $low = min($values);
        $high = max($values);
        $span = max(10.0, $high - $low);
        $step = $span > 60 ? 20 : ($span > 25 ? 10 : 5);
        $min = (int) (floor($low / $step) * $step);
        $max = (int) (ceil($high / $step) * $step);
        $ticks = [];

        for ($value = $min; $value <= $max; $value += $step) {
            $ticks[] = [$value, $value === 0 ? $zeroLabel : $this->trans('percent', ['%value%' => sprintf('%+d', $value)])];
        }

        return [$min, $max, $ticks];
    }

    private function monthLabel(string $month, bool $long = false): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m', $month, new DateTimeZone('UTC'));

        if ($date === false) {
            return $month;
        }

        $formatter = new IntlDateFormatter($this->translator->getLocale(), IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', null, $long ? 'LLLL y' : 'LLL yy');

        return (string) $formatter->format($date);
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
