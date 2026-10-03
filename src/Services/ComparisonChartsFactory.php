<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use DateTimeImmutable;
use DateTimeZone;
use IntlDateFormatter;
use Nette\Utils\Strings;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\ComparisonChartCard;
use SpeedPuzzling\Web\Results\ComparisonResult;
use SpeedPuzzling\Web\Results\ComparisonSubject;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

/**
 * The members' charts on the Charts tab of the compare page (docs/features/player-comparison.md "Charts"): Chart.js
 * models + takeaway lines built from ComparisonChartsData - no query.
 *
 * Emphasis encoding: the highlighted A coral, B indigo (a CVD-safe pair), everyone else gray. One axis per chart, thin
 * marks, recessive grid; texts in ink colours, never in a series colour. Everything a chart says - tooltips, tick labels,
 * the labels next to bars and line ends - is written here; `comparison_chart_controller.js` only places it
 * (options.plugins.comparisonChart). A chart with too little data becomes a short note instead.
 */
readonly final class ComparisonChartsFactory
{
    public const string COLOR_A = '#fe4042';
    public const string COLOR_B = '#4e54c8';
    public const string COLOR_OTHER = '#c3c8d1';
    public const string COLOR_PARITY = '#7d879c';

    /** One-hue indigo ramp of the head-to-head grid, light → dark; white text only on the two darkest steps */
    public const array RAMP = ['#e9eafb', '#c9ccf3', '#a4a8ea', '#7c81dc', '#4e54c8'];

    /** Fewer compared puzzles (bars, dots, months with a value) than this: the chart is too thin to say anything */
    public const int MIN_POINTS = 3;

    private const string INK = '#4b566b';
    private const string INK_MUTED = '#5b616d';
    private const string GRID = '#eef0f4';
    private const string ZERO_LINE = '#aeb4be';
    private const string SURFACE = '#ffffff';
    private const string TOOLTIP_BACKGROUND = 'rgba(55, 63, 80, 0.95)';

    // Puzzle names on the lead/lag chart's axis; the controller cuts them shorter on a narrow chart
    private const int LABEL_MAX = 18;
    private const int LABEL_MAX_NARROW = 12;
    private const int NARROW_CHART_WIDTH = 300;
    private const int END_LABEL_MAX = 12;

    /** Seconds; a duration axis steps through these */
    private const array DURATION_STEPS = [5, 10, 15, 30, 60, 120, 300, 600, 900, 1200, 1800, 3600, 5400, 7200, 10800, 14400, 21600, 28800, 43200, 86400];
    private const array PERCENT_STEPS = [1, 2, 5, 10, 20, 25, 50, 100, 200, 500, 1000];

    public function __construct(
        private ChartBuilderInterface $chartBuilder,
        private ComparisonChartsData $chartsData,
        private TranslatorInterface $translator,
        private PuzzlingTimeFormatter $timeFormatter,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Who's ahead, A's time vs B's, pace by piece count, form over time and - with 3+ subjects - the head-to-head grid.
     * Nothing without a highlighted pair (fewer than two subjects to compare).
     *
     * @param list<ComparisonSubject> $subjects the line-up as the viewer may see it - names come from here
     * @return list<ComparisonChartCard>
     */
    public function cards(ComparisonResult $result, array $subjects): array
    {
        if ($result->highlightA === null || $result->highlightB === null) {
            return [];
        }

        $names = $this->names($result, $subjects);
        $roles = $this->chartsData->roles($result);
        $a = $names[$result->highlightA->toString()];
        $b = $names[$result->highlightB->toString()];

        $cards = [
            $this->leadLagCard($result, $a, $b),
            $this->scatterCard($result, $a, $b),
            $this->paceCard($result, $names, $roles, $a, $b),
            $this->formCard($result, $names, $a, $b),
        ];

        if (count($result->subjects) >= 3) {
            $cards[] = $this->matrixCard($result, $names, $roles);
        }

        return $cards;
    }

    /**
     * @param array{name: string, mid: string, isYou: bool} $a
     * @param array{name: string, mid: string, isYou: bool} $b
     */
    private function leadLagCard(ComparisonResult $result, array $a, array $b): ComparisonChartCard
    {
        $data = $this->chartsData->leadLag($result);
        $total = $data['aheadCount'] + $data['behindCount'] + $data['tiedCount'];
        $title = $this->trans('lead_lag.title');
        $summary = $this->trans('lead_lag.summary', ['%a%' => $a['name'], '%b%' => $b['name']]);

        if ($total < self::MIN_POINTS || $data['bars'] === []) {
            return new ComparisonChartCard(ComparisonChartCard::LEAD_LAG, $title, null, $summary, note: $this->tooThin());
        }

        $labels = [];
        $values = [];
        $colors = [];
        $tooltips = [];
        $maxAbs = 0;

        foreach ($data['bars'] as $bar) {
            $aFaster = $bar['deltaSeconds'] < 0;
            $labels[] = Strings::truncate($this->puzzleLabel($bar['puzzleName'], $bar['piecesCount']), self::LABEL_MAX);
            $values[] = $bar['deltaSeconds'];
            $colors[] = $aFaster ? self::COLOR_A : self::COLOR_B;
            $maxAbs = max($maxAbs, abs($bar['deltaSeconds']));
            $tooltips[] = [
                'title' => $this->puzzleTitle($bar['puzzleName'], $bar['piecesCount']),
                'lines' => [
                    $this->timeOf($a['name'], $bar['aSeconds']),
                    $this->timeOf($b['name'], $bar['bSeconds']),
                    $this->trans('lead_lag.faster_by', [
                        '%name%' => ($aFaster ? $a : $b)['name'],
                        '%time%' => $this->shortTime(abs($bar['deltaSeconds'])),
                    ]),
                ],
            ];
        }

        $step = self::niceStep(self::DURATION_STEPS, $maxAbs, 2);
        $limit = $step * max(1, (int) ceil($maxAbs / $step));
        $ticks = [];

        for ($value = -$limit; $value <= $limit; $value += $step) {
            $ticks[] = [$value, $this->deltaTickLabel(abs($value), $step, $limit)];
        }

        // Selective labels: the biggest lead and the biggest lag, beside the zero line on the empty side of their row
        $valueLabels = [];
        $last = count($values) - 1;

        if ($values[0] < 0) {
            $valueLabels[] = ['datasetIndex' => 0, 'index' => 0, 'text' => $this->shortTime(abs($values[0]))];
        }

        if ($values[$last] > 0) {
            $valueLabels[] = ['datasetIndex' => 0, 'index' => $last, 'text' => $this->shortTime($values[$last])];
        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_BAR);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [[
                'label' => $summary,
                'data' => $values,
                'backgroundColor' => $colors,
                'hoverBackgroundColor' => $colors,
                // Rounded data end, square at the zero line
                'borderRadius' => 4,
                'borderSkipped' => 'start',
                'maxBarThickness' => 14,
                'categoryPercentage' => 0.9,
                'barPercentage' => 0.9,
            ]],
        ]);
        $chart->setOptions($this->options([
            'indexAxis' => 'y',
            // The whole row answers, not only the bar - short bars are hard to hit
            'interaction' => ['mode' => 'nearest', 'axis' => 'y', 'intersect' => false],
            'scales' => [
                'x' => $this->linearAxis(-$limit, $limit),
                'y' => [
                    'grid' => ['display' => false],
                    'border' => ['display' => false],
                    'ticks' => ['color' => self::INK, 'font' => ['size' => 11], 'autoSkip' => false],
                ],
            ],
        ], [
            'tooltips' => [$tooltips],
            'ticks' => ['x' => $ticks],
            'zeroLine' => ['axis' => 'x', 'color' => self::ZERO_LINE],
            'truncate' => ['axis' => 'y', 'wide' => self::LABEL_MAX, 'narrow' => self::LABEL_MAX_NARROW, 'breakpoint' => self::NARROW_CHART_WIDTH],
            'valueLabels' => $valueLabels,
        ]));

        return new ComparisonChartCard(
            key: ComparisonChartCard::LEAD_LAG,
            title: $title,
            takeaway: $this->leadLagTakeaway($data, $a, $b, $total),
            summary: $summary,
            chart: $chart,
            height: max(120, count($values) * 24 + 36),
            legend: [
                ['label' => $this->trans('lead_lag.faster', ['%name%' => $a['name']]), 'color' => self::COLOR_A, 'shape' => 'bar'],
                ['label' => $this->trans('lead_lag.faster', ['%name%' => $b['name']]), 'color' => self::COLOR_B, 'shape' => 'bar'],
            ],
            legendNote: $this->leadLagNote(count($values), $data['aheadCount'] + $data['behindCount'], $data['tiedCount']),
            axisStart: $this->trans('lead_lag.axis_start', ['%name%' => $a['name']]),
            axisEnd: $this->trans('lead_lag.axis_end', ['%name%' => $b['name']]),
        );
    }

    /**
     * "20 of 39 shown: the biggest leads and lags · 2 dead heats" - what the bars leave out
     */
    private function leadLagNote(int $shown, int $decided, int $tied): null|string
    {
        $parts = [];

        if ($shown < $decided) {
            $parts[] = $this->trans('lead_lag.shown', ['%shown%' => $shown, '%total%' => $decided]);
        }

        if ($tied > 0) {
            $parts[] = $this->trans('lead_lag.ties', ['%count%' => $tied]);
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * "You lead on 26 of 41 puzzles · biggest lead 36:32 on Autumn Lake"
     *
     * @param array{a: null|string, b: null|string, bars: list<array{puzzleId: string, puzzleName: null|string, piecesCount: int, aSeconds: int, bSeconds: int, deltaSeconds: int}>, aheadCount: int, behindCount: int, tiedCount: int} $data
     * @param array{name: string, mid: string, isYou: bool} $a
     * @param array{name: string, mid: string, isYou: bool} $b
     */
    private function leadLagTakeaway(array $data, array $a, array $b, int $total): string
    {
        $ahead = $data['aheadCount'];
        $behind = $data['behindCount'];

        if ($ahead === $behind) {
            return $this->trans('lead_lag.takeaway.level', ['%count%' => $ahead, '%total%' => $total]);
        }

        // The leader has bars on its side: A's biggest lead is the first bar, B's the last
        [$leader, $count, $bar] = $ahead > $behind
            ? [$a, $ahead, $data['bars'][0]]
            : [$b, $behind, $data['bars'][count($data['bars']) - 1]];

        // The noun goes with the total: "on 1 of 1 puzzle", "on 37 of 53 puzzles"
        $lead = $this->subjectTrans('lead_lag.takeaway', $leader, ['%wins%' => $count, '%count%' => $total]);
        $time = $this->shortTime(abs($bar['deltaSeconds']));
        $biggest = $bar['puzzleName'] !== null
            ? $this->trans('lead_lag.takeaway.biggest_on', ['%time%' => $time, '%puzzle%' => $bar['puzzleName']])
            : $this->trans('lead_lag.takeaway.biggest', ['%time%' => $time]);

        return $lead . ' · ' . $biggest;
    }

    /**
     * @param array{name: string, mid: string, isYou: bool} $a
     * @param array{name: string, mid: string, isYou: bool} $b
     */
    private function scatterCard(ComparisonResult $result, array $a, array $b): ComparisonChartCard
    {
        $data = $this->chartsData->scatter($result);
        $title = match (true) {
            $a['isYou'] => $this->trans('scatter.title.a_you', ['%name%' => $b['name']]),
            $b['isYou'] => $this->trans('scatter.title.b_you', ['%name%' => $a['name']]),
            default => $this->trans('scatter.title.other', ['%a%' => $a['name'], '%b%' => $b['name']]),
        };
        $summary = $this->trans('scatter.summary', ['%a%' => $a['name'], '%b%' => $b['name']]);

        if (count($data['points']) < self::MIN_POINTS) {
            return new ComparisonChartCard(ComparisonChartCard::SCATTER, $title, null, $summary, note: $this->tooThin());
        }

        $step = self::niceStep(array_values(array_filter(self::DURATION_STEPS, static fn(int $step): bool => $step >= 60)), $data['maxSeconds'], 4);
        $limit = $step * max(1, (int) ceil($data['maxSeconds'] / $step));

        /** @var array<'a'|'b'|'tie', array{points: list<array{x: int, y: int}>, tooltips: list<array{title: string, lines: list<string>}>}> $groups */
        $groups = [
            'a' => ['points' => [], 'tooltips' => []],
            'b' => ['points' => [], 'tooltips' => []],
            'tie' => ['points' => [], 'tooltips' => []],
        ];

        foreach ($data['points'] as $point) {
            // A up, B across: below the parity line A was faster
            $groups[$point['winner']]['points'][] = ['x' => $point['bSeconds'], 'y' => $point['aSeconds']];
            $groups[$point['winner']]['tooltips'][] = [
                'title' => $this->puzzleTitle($point['puzzleName'], $point['piecesCount']),
                'lines' => [$this->timeOf($a['name'], $point['aSeconds']), $this->timeOf($b['name'], $point['bSeconds'])],
            ];
        }

        $colors = ['a' => self::COLOR_A, 'b' => self::COLOR_B, 'tie' => self::COLOR_PARITY];
        $datasetLabels = [
            'a' => $this->trans('lead_lag.faster', ['%name%' => $a['name']]),
            'b' => $this->trans('lead_lag.faster', ['%name%' => $b['name']]),
            'tie' => $this->trans('scatter.same_time'),
        ];
        $datasets = [];
        $tooltips = [];

        foreach ($groups as $winner => $group) {
            $datasets[] = [
                'label' => $datasetLabels[$winner],
                'data' => $group['points'],
                'pointBackgroundColor' => $colors[$winner],
                'pointBorderColor' => self::SURFACE,
                'pointBorderWidth' => 2,
                'pointRadius' => 5,
                'pointHoverRadius' => 7,
                'pointHitRadius' => 6,
                'backgroundColor' => $colors[$winner],
                'borderColor' => self::SURFACE,
                // The first dataset is drawn on top
                'order' => count($datasets),
            ];
            $tooltips[] = $group['tooltips'];
        }

        // The dashed parity line: the same time for both
        $datasets[] = [
            'type' => 'line',
            'label' => $this->trans('scatter.same_time'),
            'data' => [['x' => 0, 'y' => 0], ['x' => $limit, 'y' => $limit]],
            'borderColor' => self::COLOR_PARITY,
            'borderWidth' => 1.5,
            'borderDash' => [5, 4],
            'pointRadius' => 0,
            'pointHoverRadius' => 0,
            'pointHitRadius' => 0,
            'fill' => false,
            'order' => count($datasets),
        ];
        $tooltips[] = [];

        $ticks = [];

        for ($value = 0; $value <= $limit; $value += $step) {
            $ticks[] = [$value, $value === 0 ? '0' : $this->durationTick($value)];
        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_SCATTER);
        $chart->setData(['datasets' => $datasets]);
        $chart->setOptions($this->options([
            'interaction' => ['mode' => 'nearest', 'intersect' => false],
            'scales' => [
                'x' => $this->linearAxis(0, $limit),
                'y' => $this->linearAxis(0, $limit),
            ],
        ], [
            'tooltips' => $tooltips,
            'ticks' => ['x' => $ticks, 'y' => $ticks],
        ]));

        $legend = [
            ['label' => $datasetLabels['a'], 'color' => self::COLOR_A, 'shape' => 'dot'],
            ['label' => $datasetLabels['b'], 'color' => self::COLOR_B, 'shape' => 'dot'],
            ['label' => $datasetLabels['tie'], 'color' => self::COLOR_PARITY, 'shape' => 'dash'],
        ];

        return new ComparisonChartCard(
            key: ComparisonChartCard::SCATTER,
            title: $title,
            takeaway: $this->scatterTakeaway($data['points'], $a, $b),
            summary: $summary,
            chart: $chart,
            height: 280,
            legend: $legend,
            axisStart: $this->subjectTrans('scatter.axis_y', $a),
            axisEnd: $this->subjectTrans('scatter.axis_x', $b),
        );
    }

    /**
     * Whether one of the two wins more often on the longer half of the puzzles (a quarter of the puzzles more), else how
     * many dots lie below the line.
     *
     * @param list<array{puzzleId: string, puzzleName: null|string, piecesCount: int, aSeconds: int, bSeconds: int, winner: 'a'|'b'|'tie'}> $points
     * @param array{name: string, mid: string, isYou: bool} $a
     * @param array{name: string, mid: string, isYou: bool} $b
     */
    private function scatterTakeaway(array $points, array $a, array $b): string
    {
        $count = count($points);

        if ($count >= 6) {
            usort($points, static fn(array $x, array $y): int => ($x['aSeconds'] + $x['bSeconds']) <=> ($y['aSeconds'] + $y['bSeconds']));
            $half = intdiv($count, 2);
            $shorterWins = 0;
            $longerWins = 0;

            foreach ($points as $index => $point) {
                if ($point['winner'] !== 'a') {
                    continue;
                }

                if ($index < $half) {
                    $shorterWins++;
                } elseif ($index >= $count - $half) {
                    $longerWins++;
                }
            }

            $shorter = $shorterWins / $half;
            $longer = $longerWins / $half;

            if (abs($longer - $shorter) >= 0.25) {
                [$first, $second] = $longer > $shorter ? [$a, $b] : [$b, $a];

                return $this->subjectTrans('scatter.takeaway.longer', $first, ['%other%' => $second['mid']]);
            }
        }

        $below = count(array_filter($points, static fn(array $point): bool => $point['winner'] === 'a'));

        return $this->subjectTrans('scatter.takeaway.below', $a, ['%wins%' => $below, '%count%' => $count]);
    }

    /**
     * @param array<string, array{name: string, mid: string, isYou: bool}> $names
     * @param array<string, 'a'|'b'|'other'> $roles
     * @param array{name: string, mid: string, isYou: bool} $a
     * @param array{name: string, mid: string, isYou: bool} $b
     */
    private function paceCard(ComparisonResult $result, array $names, array $roles, array $a, array $b): ComparisonChartCard
    {
        $buckets = $this->chartsData->paceByPieces($result);
        $title = $this->trans('pace.title');
        $bucketLabels = array_map(fn(array $bucket): string => $this->trans('pace.bucket', ['%pieces%' => $bucket['label']]), $buckets);
        $summary = $this->trans('pace.summary', ['%buckets%' => implode(', ', array_column($buckets, 'label'))]);

        // Puzzles behind the dots: per bucket at least the most any one subject compared there
        $puzzles = 0;

        foreach ($buckets as $bucket) {
            $puzzles += max([0, ...array_column($bucket['subjects'], 'puzzles')]);
        }

        if ($puzzles < self::MIN_POINTS) {
            return new ComparisonChartCard(ComparisonChartCard::PACE, $title, null, $summary, note: $this->tooThin());
        }

        /** @var array<'a'|'b'|'other', array{points: list<array{x: float, y: string}>, tooltips: list<array{title: string, lines: list<string>}>}> $groups */
        $groups = [
            'a' => ['points' => [], 'tooltips' => []],
            'b' => ['points' => [], 'tooltips' => []],
            'other' => ['points' => [], 'tooltips' => []],
        ];
        $maxAbs = 0.0;

        foreach ($buckets as $index => $bucket) {
            foreach ($bucket['subjects'] as $ref => $value) {
                $role = $roles[$ref] ?? 'other';
                $groups[$role]['points'][] = ['x' => round($value['percent'], 1), 'y' => $bucketLabels[$index]];
                $groups[$role]['tooltips'][] = [
                    'title' => $this->trans('pieces', ['%pieces%' => $bucket['label']]),
                    'lines' => [
                        $this->pacePhrase($names[$ref]['name'] ?? '', $value['percent']),
                        $this->trans('pace.puzzles', ['%count%' => $value['puzzles']]),
                    ],
                ];
                $maxAbs = max($maxAbs, abs($value['percent']));
            }
        }

        $step = self::niceStep(self::PERCENT_STEPS, $maxAbs, 2);
        $limit = $step * max(1, (int) ceil($maxAbs / $step));
        $ticks = [];

        for ($value = -$limit; $value <= $limit; $value += $step) {
            $ticks[] = [$value, $this->percentTick($value)];
        }

        $styles = [
            'a' => ['color' => self::COLOR_A, 'radius' => 6],
            'b' => ['color' => self::COLOR_B, 'radius' => 6],
            'other' => ['color' => self::COLOR_OTHER, 'radius' => 4],
        ];
        $datasets = [];
        $tooltips = [];

        foreach ($groups as $role => $group) {
            $datasets[] = [
                'label' => match ($role) {
                    'a' => $a['name'],
                    'b' => $b['name'],
                    'other' => $this->trans('rest_of_line_up'),
                },
                'data' => $group['points'],
                'pointBackgroundColor' => $styles[$role]['color'],
                'pointBorderColor' => self::SURFACE,
                'pointBorderWidth' => 2,
                'pointRadius' => $styles[$role]['radius'],
                'pointHoverRadius' => $styles[$role]['radius'] + 2,
                'pointHitRadius' => 6,
                'backgroundColor' => $styles[$role]['color'],
                'borderColor' => self::SURFACE,
                'order' => count($datasets),
            ];
            $tooltips[] = $group['tooltips'];
        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_SCATTER);
        $chart->setData(['datasets' => $datasets]);
        $chart->setOptions($this->options([
            'interaction' => ['mode' => 'nearest', 'intersect' => false],
            'scales' => [
                'x' => $this->linearAxis(-$limit, $limit),
                'y' => [
                    'type' => 'category',
                    'labels' => $bucketLabels,
                    'offset' => true,
                    'grid' => ['color' => self::GRID, 'drawTicks' => false, 'offset' => false],
                    'border' => ['display' => false],
                    'ticks' => ['color' => self::INK, 'font' => ['size' => 12], 'padding' => 8],
                ],
            ],
        ], [
            'tooltips' => $tooltips,
            'ticks' => ['x' => $ticks],
            'zeroLine' => ['axis' => 'x', 'color' => self::ZERO_LINE],
        ]));

        $legend = [
            ['label' => $a['name'], 'color' => self::COLOR_A, 'shape' => 'dot'],
            ['label' => $b['name'], 'color' => self::COLOR_B, 'shape' => 'dot'],
        ];

        if ($groups['other']['points'] !== []) {
            $legend[] = ['label' => $this->trans('rest_of_line_up'), 'color' => self::COLOR_OTHER, 'shape' => 'dot'];
        }

        return new ComparisonChartCard(
            key: ComparisonChartCard::PACE,
            title: $title,
            takeaway: $this->paceTakeaway($buckets, $result, $a, $b),
            summary: $summary,
            chart: $chart,
            height: count($buckets) * 40 + 44,
            legend: $legend,
            axisStart: $this->trans('pace.axis_start'),
            axisEnd: $this->trans('pace.axis_end'),
        );
    }

    /**
     * "Quickest against the line-up: you at 1000 pieces, Kateřina N. at 1500 pieces" - for each of the pair compared in
     * two or more buckets; one bucket only: where each of them stands there.
     *
     * @param list<array{key: string, label: string, subjects: array<string, array{percent: float, puzzles: int}>}> $buckets
     * @param array{name: string, mid: string, isYou: bool} $a
     * @param array{name: string, mid: string, isYou: bool} $b
     */
    private function paceTakeaway(array $buckets, ComparisonResult $result, array $a, array $b): null|string
    {
        $pair = [];

        if ($result->highlightA !== null) {
            $pair[$result->highlightA->toString()] = $a;
        }

        if ($result->highlightB !== null) {
            $pair[$result->highlightB->toString()] = $b;
        }

        if (count($buckets) === 1) {
            $items = [];

            foreach ($pair as $ref => $subject) {
                if (isset($buckets[0]['subjects'][$ref])) {
                    $items[] = $this->trans('pace.takeaway.single_item', [
                        '%name%' => $subject['mid'],
                        '%percent%' => $this->percentTick((int) round($buckets[0]['subjects'][$ref]['percent'])),
                    ]);
                }
            }

            return $items === [] ? null : $this->trans('pace.takeaway.single', [
                '%pieces%' => $buckets[0]['label'],
                '%list%' => implode(', ', $items),
            ]);
        }

        $items = [];

        foreach ($pair as $ref => $subject) {
            $best = null;
            $compared = 0;

            foreach ($buckets as $bucket) {
                if (isset($bucket['subjects'][$ref]) === false) {
                    continue;
                }

                $compared++;

                if ($best === null || $bucket['subjects'][$ref]['percent'] < $best['percent']) {
                    $best = ['percent' => $bucket['subjects'][$ref]['percent'], 'label' => $bucket['label']];
                }
            }

            if ($best !== null && $compared >= 2) {
                $items[] = $this->trans('pace.takeaway.quickest_item', ['%name%' => $subject['mid'], '%pieces%' => $best['label']]);
            }
        }

        return $items === [] ? null : $this->trans('pace.takeaway.quickest', ['%list%' => implode(', ', $items)]);
    }

    /**
     * @param array<string, array{name: string, mid: string, isYou: bool}> $names
     * @param array{name: string, mid: string, isYou: bool} $a
     * @param array{name: string, mid: string, isYou: bool} $b
     */
    private function formCard(ComparisonResult $result, array $names, array $a, array $b): ComparisonChartCard
    {
        $data = $this->chartsData->form($result, $this->clock->now());
        $title = $this->trans('form.title');
        $months = array_map(static fn(string $month): DateTimeImmutable => self::month($month), $data['months']);
        $from = $this->formatMonth($months[0], 'LLL yyyy');
        $to = $this->formatMonth($months[count($months) - 1], 'LLL yyyy');
        $summary = $this->trans('form.summary', ['%from%' => $from, '%to%' => $to]);

        // A and B first: drawn on top, first in the tooltip
        $order = [];

        if ($result->highlightA !== null) {
            $order[] = $result->highlightA->toString();
        }

        if ($result->highlightB !== null) {
            $order[] = $result->highlightB->toString();
        }

        foreach ($result->subjects as $subject) {
            if (in_array($subject->toString(), $order, true) === false) {
                $order[] = $subject->toString();
            }
        }

        $points = 0;
        $longest = 0;

        foreach ($data['series'] as $values) {
            $filled = count(array_filter($values, static fn(null|float $value): bool => $value !== null));
            $points += $filled;
            $longest = max($longest, $filled);
        }

        if ($points < self::MIN_POINTS || $longest < 2) {
            return new ComparisonChartCard(ComparisonChartCard::FORM, $title, null, $summary, note: $this->tooThin());
        }

        $labels = array_map(fn(DateTimeImmutable $month): string => $this->formatMonth($month, 'LLL'), $months);
        $longLabels = array_map(fn(DateTimeImmutable $month): string => $this->formatMonth($month, 'LLLL yyyy'), $months);
        $datasets = [];
        $tooltips = [];
        $maxAbs = 0.0;

        foreach ($order as $index => $ref) {
            $name = $names[$ref]['name'] ?? '';
            $emphasis = match ($index) {
                0 => self::COLOR_A,
                1 => self::COLOR_B,
                default => null,
            };
            $values = [];
            $pointTooltips = [];

            foreach ($data['series'][$ref] ?? [] as $monthIndex => $pace) {
                // Up = faster: the line shows how much faster than the line-up's median
                $values[] = $pace === null ? null : round(-$pace, 1);
                $pointTooltips[] = $pace === null ? null : [
                    'title' => $longLabels[$monthIndex],
                    'lines' => [$this->pacePhrase($name, $pace)],
                ];
                $maxAbs = max($maxAbs, abs($pace ?? 0.0));
            }

            $datasets[] = [
                'label' => $name,
                'data' => $values,
                'borderColor' => $emphasis ?? self::COLOR_OTHER,
                'backgroundColor' => $emphasis ?? self::COLOR_OTHER,
                'borderWidth' => $emphasis !== null ? 2 : 1.25,
                'pointRadius' => $emphasis !== null ? 3 : 1.5,
                'pointHoverRadius' => $emphasis !== null ? 5 : 3,
                'pointBorderColor' => self::SURFACE,
                'pointBorderWidth' => $emphasis !== null ? 1 : 0,
                'pointHitRadius' => 6,
                'borderCapStyle' => 'round',
                'borderJoinStyle' => 'round',
                // A month without a time is no break in someone's form
                'spanGaps' => true,
                'tension' => 0,
                'fill' => false,
                'order' => $index,
            ];
            $tooltips[] = $pointTooltips;
        }

        $step = self::niceStep(self::PERCENT_STEPS, $maxAbs, 3);
        $limit = $step * max(1, (int) ceil($maxAbs / $step));
        $ticks = [];

        for ($value = -$limit; $value <= $limit; $value += $step) {
            $ticks[] = [$value, $this->percentTick($value)];
        }

        $endLabels = [
            ['datasetIndex' => 0, 'text' => Strings::truncate($a['name'], self::END_LABEL_MAX)],
            ['datasetIndex' => 1, 'text' => Strings::truncate($b['name'], self::END_LABEL_MAX)],
        ];
        $longestEndLabel = max(array_map(static fn(array $label): int => mb_strlen($label['text']), $endLabels));

        $chart = $this->chartBuilder->createChart(Chart::TYPE_LINE);
        $chart->setData(['labels' => $labels, 'datasets' => $datasets]);
        $chart->setOptions($this->options([
            // Room for the names right of the lines' ends
            'layout' => ['padding' => ['right' => 8 + (int) ceil($longestEndLabel * 6.5)]],
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'scales' => [
                'x' => [
                    'grid' => ['display' => false],
                    'border' => ['color' => self::GRID],
                    'ticks' => ['color' => self::INK_MUTED, 'font' => ['size' => 11], 'maxRotation' => 0, 'autoSkipPadding' => 8],
                ],
                'y' => $this->linearAxis(-$limit, $limit),
            ],
        ], [
            'tooltips' => $tooltips,
            'ticks' => ['y' => $ticks],
            'zeroLine' => ['axis' => 'y', 'color' => self::ZERO_LINE],
            'endLabels' => $endLabels,
        ]));

        $legend = [
            ['label' => $a['name'], 'color' => self::COLOR_A, 'shape' => 'line'],
            ['label' => $b['name'], 'color' => self::COLOR_B, 'shape' => 'line'],
        ];

        if (count($order) > 2) {
            $legend[] = ['label' => $this->trans('rest_of_line_up'), 'color' => self::COLOR_OTHER, 'shape' => 'line'];
        }

        return new ComparisonChartCard(
            key: ComparisonChartCard::FORM,
            title: $title,
            takeaway: $this->formTakeaway($data['series'], $result, $longLabels, $a, $b),
            summary: $summary,
            chart: $chart,
            height: 220,
            legend: $legend,
            axisStart: $this->trans('form.axis_start'),
            axisEnd: $this->trans('form.axis_end', ['%from%' => $from, '%to%' => $to]),
        );
    }

    /**
     * "You gained 6 points on the line-up since November 2025" - A's first month with a time against its last; B when A
     * has fewer than two such months.
     *
     * @param array<string, list<null|float>> $series
     * @param list<string> $monthNames
     * @param array{name: string, mid: string, isYou: bool} $a
     * @param array{name: string, mid: string, isYou: bool} $b
     */
    private function formTakeaway(array $series, ComparisonResult $result, array $monthNames, array $a, array $b): null|string
    {
        foreach ([[$result->highlightA, $a], [$result->highlightB, $b]] as [$ref, $subject]) {
            $values = array_filter($series[$ref?->toString() ?? ''] ?? [], static fn(null|float $value): bool => $value !== null);

            if (count($values) < 2) {
                continue;
            }

            $firstMonth = (int) array_key_first($values);
            $first = (float) $values[$firstMonth];
            $last = (float) $values[(int) array_key_last($values)];
            // Pace: lower is faster, so a drop is a gain
            $points = (int) round($first - $last);
            $parameters = ['%name%' => $subject['name'], '%month%' => $monthNames[$firstMonth], '%count%' => abs($points)];

            return match (true) {
                $points > 0 => $this->trans('form.takeaway.gained', $parameters),
                $points < 0 => $this->trans('form.takeaway.lost', $parameters),
                default => $this->trans('form.takeaway.steady', $parameters),
            };
        }

        return null;
    }

    /**
     * @param array<string, array{name: string, mid: string, isYou: bool}> $names
     * @param array<string, 'a'|'b'|'other'> $roles
     */
    private function matrixCard(ComparisonResult $result, array $names, array $roles): ComparisonChartCard
    {
        $data = $this->chartsData->matrix($result);
        $title = $this->trans('matrix.title');
        $summary = $this->trans('matrix.summary');

        if ($data['maxShared'] === 0) {
            return new ComparisonChartCard(ComparisonChartCard::MATRIX, $title, null, $summary, note: $this->tooThin());
        }

        $abbreviations = $this->abbreviations($data['subjects'], $names);
        $columns = [];

        foreach ($data['subjects'] as $ref) {
            $columns[] = ['abbr' => $abbreviations[$ref], 'name' => $names[$ref]['name']];
        }

        $rows = [];
        $best = null;

        foreach ($data['subjects'] as $rowRef) {
            $cells = [];

            foreach ($data['subjects'] as $columnRef) {
                if ($rowRef === $columnRef) {
                    $cells[] = ['diagonal' => true, 'wins' => null, 'step' => null, 'title' => ''];

                    continue;
                }

                $cell = $data['cells'][$rowRef][$columnRef] ?? ['wins' => 0, 'shared' => 0, 'share' => null];
                $parameters = ['%row%' => $names[$rowRef]['name'], '%column%' => $names[$columnRef]['mid']];

                $cells[] = [
                    'diagonal' => false,
                    'wins' => $cell['shared'] > 0 ? $cell['wins'] : null,
                    'step' => $cell['share'] !== null ? min(count(self::RAMP), 1 + (int) floor($cell['share'] * count(self::RAMP))) : null,
                    'title' => $cell['shared'] > 0
                        ? $this->trans('matrix.cell', $parameters + ['%wins%' => $cell['wins'], '%count%' => $cell['shared']])
                        : $this->trans('matrix.cell_none', $parameters),
                ];

                if ($cell['wins'] > 0 && ($best === null || $cell['wins'] > $best['wins'])) {
                    $best = ['wins' => $cell['wins'], 'row' => $rowRef, 'column' => $columnRef];
                }
            }

            $rows[] = ['name' => $names[$rowRef]['name'], 'role' => $roles[$rowRef] ?? 'other', 'cells' => $cells];
        }

        return new ComparisonChartCard(
            key: ComparisonChartCard::MATRIX,
            title: $title,
            takeaway: $best === null ? null : $this->trans('matrix.takeaway', [
                '%row%' => $names[$best['row']]['mid'],
                '%column%' => $names[$best['column']]['mid'],
                '%count%' => $best['wins'],
            ]),
            summary: $summary,
            grid: [
                'columns' => $columns,
                'rows' => $rows,
                'ramp' => self::RAMP,
                'rampLow' => $this->trans('matrix.ramp_low'),
                'rampHigh' => $this->trans('matrix.ramp_high'),
            ],
        );
    }

    /**
     * Short column headers: "You", initials ("KN", "SE"); initials two subjects share become the first letters of the
     * name, still equal ones get a number.
     *
     * @param list<string> $refs
     * @param array<string, array{name: string, mid: string, isYou: bool}> $names
     * @return array<string, string>
     */
    private function abbreviations(array $refs, array $names): array
    {
        $abbreviations = [];

        foreach ($refs as $ref) {
            $abbreviations[$ref] = $names[$ref]['isYou'] ? $names[$ref]['name'] : self::initials($names[$ref]['name']);
        }

        $counts = array_count_values($abbreviations);

        foreach ($abbreviations as $ref => $abbreviation) {
            if ($counts[$abbreviation] > 1 && $names[$ref]['isYou'] === false) {
                $abbreviations[$ref] = mb_substr(ltrim($names[$ref]['name'], '#'), 0, 3);
            }
        }

        $counts = array_count_values($abbreviations);
        $seen = [];

        foreach ($abbreviations as $ref => $abbreviation) {
            if ($counts[$abbreviation] > 1) {
                $seen[$abbreviation] = ($seen[$abbreviation] ?? 0) + 1;
                $abbreviations[$ref] = $abbreviation . $seen[$abbreviation];
            }
        }

        return $abbreviations;
    }

    private static function initials(string $name): string
    {
        $words = preg_split('/[\s&,.]+/u', ltrim($name, '#'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $initials = '';

        foreach (array_slice($words, 0, 2) as $word) {
            $initials .= mb_strtoupper(mb_substr($word, 0, 1));
        }

        return $initials !== '' ? $initials : '?';
    }

    /**
     * Display names by ref: "You" for the viewer's own solo subject, a player's name, a pair/team's name or its members.
     * `mid` is the form inside a sentence ("you").
     *
     * @param list<ComparisonSubject> $subjects
     * @return array<string, array{name: string, mid: string, isYou: bool}>
     */
    private function names(ComparisonResult $result, array $subjects): array
    {
        $byRef = [];

        foreach ($subjects as $subject) {
            $byRef[$subject->ref->toString()] = $subject;
        }

        $names = [];

        foreach ($result->subjects as $ref) {
            $subject = $byRef[$ref->toString()] ?? null;

            if ($subject !== null && $subject->isAvailable && $subject->isViewer && $subject->isTeam() === false) {
                $names[$ref->toString()] = ['name' => $this->trans('you'), 'mid' => $this->trans('you_mid'), 'isYou' => true];

                continue;
            }

            $name = $this->subjectName($subject);
            $names[$ref->toString()] = ['name' => $name, 'mid' => $name, 'isYou' => false];
        }

        return $names;
    }

    private function subjectName(null|ComparisonSubject $subject): string
    {
        if ($subject === null || $subject->isAvailable === false) {
            return $this->trans('unavailable');
        }

        if ($subject->isTeam() === false) {
            return $subject->playerName !== null && $subject->playerName !== ''
                ? $subject->playerName
                : '#' . strtoupper((string) $subject->playerCode);
        }

        if ($subject->teamName !== null && trim($subject->teamName) !== '') {
            return $subject->teamName;
        }

        $members = [];

        foreach ($subject->members as $member) {
            $members[] = match (true) {
                $member->isPrivate => $this->translator->trans('secret_puzzler_name'),
                $member->playerName !== null && $member->playerName !== '' => $member->playerName,
                $member->guestName !== null => $member->guestName,
                default => '#' . $member->playerCode,
            };
        }

        return $members === [] ? $this->trans('unavailable') : implode(count($members) === 2 ? ' & ' : ', ', $members);
    }

    /**
     * Shared options of every chart; `$plugin` goes to the Stimulus controller.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $plugin
     * @return array<string, mixed>
     */
    private function options(array $options, array $plugin): array
    {
        $options['maintainAspectRatio'] = false;
        $options['plugins'] = [
            // The legend is HTML under the chart
            'legend' => ['display' => false],
            'tooltip' => [
                'backgroundColor' => self::TOOLTIP_BACKGROUND,
                'padding' => 8,
                'boxPadding' => 4,
                'usePointStyle' => true,
            ],
            'comparisonChart' => $plugin + ['labelColor' => self::INK],
        ];

        return $options;
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
            'ticks' => ['color' => self::INK_MUTED, 'font' => ['size' => 11], 'maxRotation' => 0, 'padding' => 6],
        ];
    }

    /**
     * The smallest step that splits $max into at most $maxTicks parts.
     *
     * @param list<int> $steps
     */
    private static function niceStep(array $steps, float|int $max, int $maxTicks): int
    {
        foreach ($steps as $step) {
            if (ceil($max / $step) <= $maxTicks) {
                return $step;
            }
        }

        $largest = $steps[count($steps) - 1];

        return $largest * (int) ceil($max / $largest / $maxTicks);
    }

    private function tooThin(): string
    {
        return $this->trans('too_thin', ['%count%' => self::MIN_POINTS]);
    }

    private function puzzleLabel(null|string $name, int $pieces): string
    {
        return $name ?? $this->trans('puzzle_fallback', ['%pieces%' => $pieces]);
    }

    private function puzzleTitle(null|string $name, int $pieces): string
    {
        $pieceCount = $this->trans('pieces', ['%pieces%' => $pieces]);

        return $name !== null ? $name . ' · ' . $pieceCount : $pieceCount;
    }

    private function timeOf(string $name, int $seconds): string
    {
        return $this->trans('time_of', ['%name%' => $name, '%time%' => $this->timeFormatter->formatTime($seconds)]);
    }

    /**
     * "36:32", or with hours "01:02:05" - the leaderboard's gap format without its plus
     */
    private function shortTime(int $seconds): string
    {
        return ltrim($this->timeFormatter->gapTime($seconds), '+');
    }

    /**
     * H:MM - "1:30" is an hour and a half
     */
    private function durationTick(int $seconds): string
    {
        return sprintf('%d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }

    /**
     * A time difference on the lead/lag axis, the same unit across the axis: H:MM from an hour, else minutes or seconds.
     */
    private function deltaTickLabel(int $seconds, int $step, int $limit): string
    {
        return match (true) {
            $seconds === 0 => '0',
            $limit >= 3600 => $this->durationTick($seconds),
            $step % 60 === 0 => $this->trans('axis.minutes', ['%count%' => intdiv($seconds, 60)]),
            default => $this->trans('axis.seconds', ['%count%' => $seconds]),
        };
    }

    private function percentTick(int $value): string
    {
        if ($value === 0) {
            return '0';
        }

        // A real minus sign, like a typeset axis
        return $this->trans('axis.percent', ['%value%' => ($value > 0 ? '+' : '−') . abs($value)]);
    }

    /**
     * "Kateřina N.: 12 % faster than the line-up median" - pace is + slower / − faster
     */
    private function pacePhrase(string $name, float $pace): string
    {
        $percent = (int) round(abs($pace));

        if ($percent === 0) {
            return $this->trans('pace.even', ['%name%' => $name]);
        }

        return $this->trans($pace < 0 ? 'pace.faster' : 'pace.slower', ['%name%' => $name, '%percent%' => $percent]);
    }

    private static function month(string $month): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m', $month, new DateTimeZone('UTC'));
        assert($date !== false);

        return $date;
    }

    private function formatMonth(DateTimeImmutable $month, string $pattern): string
    {
        $formatter = new IntlDateFormatter($this->translator->getLocale(), IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', null, $pattern);
        $formatted = $formatter->format($month);

        return is_string($formatted) ? $formatted : $month->format('Y-m');
    }

    /**
     * The `.you` or `.other` variant of a sentence whose subject is $subject (verbs agree: "You lead" / "Kateřina leads").
     *
     * @param array{name: string, mid: string, isYou: bool} $subject
     * @param array<string, int|string> $parameters
     */
    private function subjectTrans(string $key, array $subject, array $parameters = []): string
    {
        return $this->trans($key . ($subject['isYou'] ? '.you' : '.other'), $parameters + ['%name%' => $subject['name']]);
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans('comparison_charts.' . $key, $parameters);
    }
}
