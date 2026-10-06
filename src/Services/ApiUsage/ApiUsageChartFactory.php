<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ApiUsage;

use SpeedPuzzling\Web\Value\ApiStatusClass;
use SpeedPuzzling\Web\Value\ApiUsageMonth;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

/**
 * The daily bar chart of the API usage pages: one bar per day of the month,
 * stacked by status class (one caller) or by caller (several).
 */
final readonly class ApiUsageChartFactory
{
    /** Busiest groups drawn on their own; the rest is summed into one "other" stack */
    public const int MAX_GROUPS = 6;

    private const array STATUS_COLORS = [
        '2xx' => '#3aa76d',
        '3xx' => '#7d879c',
        '4xx' => '#f0a33a',
        '429' => '#8a5cd0',
        '5xx' => '#fe4042',
    ];

    // No red: red is the 5xx colour of the status chart
    private const array GROUP_COLORS = ['#4e54c8', '#3aa76d', '#2b9bd3', '#f0a33a', '#8a5cd0', '#20a39e'];
    private const string OTHER_COLOR = '#c3c8d1';

    public function __construct(
        private ChartBuilderInterface $chartBuilder,
    ) {
    }

    /**
     * @param array<string, array<string, int>> $dailyByStatus status class => [Y-m-d => requests]
     */
    public function byStatus(ApiUsageMonth $month, array $dailyByStatus): Chart
    {
        $datasets = [];

        foreach (ApiStatusClass::cases() as $statusClass) {
            if (!isset($dailyByStatus[$statusClass->value])) {
                continue;
            }

            $datasets[] = $this->dataset($statusClass->value, self::STATUS_COLORS[$statusClass->value], $month, $dailyByStatus[$statusClass->value]);
        }

        return $this->chart($month, $datasets);
    }

    /**
     * @param array<string, array<string, int>> $dailyByGroup group key => [Y-m-d => requests]
     * @param array<string, string> $labels group key => legend label
     */
    public function byGroup(ApiUsageMonth $month, array $dailyByGroup, array $labels, string $otherLabel): Chart
    {
        uasort($dailyByGroup, static fn (array $a, array $b): int => array_sum($b) <=> array_sum($a));

        $datasets = [];
        $other = [];
        $index = 0;

        foreach ($dailyByGroup as $group => $daily) {
            if ($index < self::MAX_GROUPS) {
                $datasets[] = $this->dataset($labels[$group] ?? $group, self::GROUP_COLORS[$index], $month, $daily);
            } else {
                foreach ($daily as $day => $requests) {
                    $other[$day] = ($other[$day] ?? 0) + $requests;
                }
            }

            $index++;
        }

        if ($other !== []) {
            $datasets[] = $this->dataset($otherLabel, self::OTHER_COLOR, $month, $other);
        }

        return $this->chart($month, $datasets);
    }

    /**
     * @param array<string, int> $daily
     * @return array<string, mixed>
     */
    private function dataset(string $label, string $color, ApiUsageMonth $month, array $daily): array
    {
        return [
            'label' => $label,
            'data' => array_map(static fn (string $day): int => $daily[$day] ?? 0, $month->days()),
            'backgroundColor' => $color,
            'borderWidth' => 0,
            'maxBarThickness' => 28,
        ];
    }

    /**
     * @param list<array<string, mixed>> $datasets
     */
    private function chart(ApiUsageMonth $month, array $datasets): Chart
    {
        $chart = $this->chartBuilder->createChart(Chart::TYPE_BAR);

        $chart->setData([
            'labels' => array_map(static fn (string $day): string => (string) (int) substr($day, 8, 2), $month->days()),
            'datasets' => $datasets,
        ]);

        $chart->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => false,
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'scales' => [
                'x' => ['stacked' => true, 'grid' => ['display' => false]],
                'y' => ['stacked' => true, 'beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
            'plugins' => [
                'legend' => ['display' => count($datasets) > 1, 'position' => 'bottom'],
            ],
        ]);

        return $chart;
    }
}
