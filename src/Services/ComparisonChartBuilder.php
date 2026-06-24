<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Results\ComparisonView;
use SpeedPuzzling\Web\Value\DifficultyTier;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

/**
 * Builds the members-only comparison charts from a computed ComparisonView.
 * All charts react to the same filters/mode as the table because they consume
 * the already-filtered view rows.
 */
final class ComparisonChartBuilder
{
    private const int MAX_PUZZLES_IN_CHART = 15;

    public function __construct(
        private readonly ChartBuilderInterface $chartBuilder,
    ) {
    }

    public function build(string $chartType, ComparisonView $view): Chart
    {
        return match ($chartType) {
            'pieces' => $this->piecesChart($view),
            'puzzles' => $this->puzzlesChart($view),
            'difficulty' => $this->difficultyChart($view),
            default => $this->winsChart($view),
        };
    }

    /**
     * Returns true when the given chart has enough data to render meaningfully.
     */
    public function hasData(string $chartType, ComparisonView $view): bool
    {
        if ($view->rows === [] || count($view->subjects) < 2) {
            return false;
        }

        if ($chartType === 'difficulty') {
            foreach ($view->rows as $row) {
                if ($row->difficultyTier !== null) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    private function winsChart(ComparisonView $view): Chart
    {
        $wins = [];

        foreach ($view->subjects as $subject) {
            $wins[$subject->key] = 0;
        }

        foreach ($view->rows as $row) {
            if ($row->solvedCount < 2) {
                continue;
            }

            $fastestKeys = [];

            foreach ($row->cells as $cell) {
                if ($cell->entry !== null && $cell->entry->isFastest) {
                    $fastestKeys[] = $cell->subject->key;
                }
            }

            if (count($fastestKeys) === 1) {
                $wins[$fastestKeys[0]]++;
            }
        }

        $labels = [];
        $data = [];
        $colors = [];

        foreach ($view->subjects as $subject) {
            $labels[] = $subject->label();
            $data[] = $wins[$subject->key];
            $colors[] = $subject->color;
        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_BAR);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [[
                'data' => $data,
                'backgroundColor' => $colors,
                'borderWidth' => 0,
                'borderRadius' => 4,
            ]],
        ]);
        $chart->setOptions([
            'maintainAspectRatio' => false,
            'scales' => [
                'x' => ['grid' => ['display' => false]],
                'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
            'plugins' => ['legend' => ['display' => false]],
        ]);

        return $chart;
    }

    private function piecesChart(ComparisonView $view): Chart
    {
        $buckets = [];

        foreach ($view->rows as $row) {
            $buckets[$row->piecesCount] = true;
        }

        $buckets = array_keys($buckets);
        sort($buckets);

        $sum = [];
        $count = [];

        foreach ($view->rows as $row) {
            foreach ($row->cells as $cell) {
                if ($cell->entry === null) {
                    continue;
                }

                $sum[$cell->subject->key][$row->piecesCount] = ($sum[$cell->subject->key][$row->piecesCount] ?? 0) + $cell->entry->fastestTime;
                $count[$cell->subject->key][$row->piecesCount] = ($count[$cell->subject->key][$row->piecesCount] ?? 0) + 1;
            }
        }

        $datasets = [];

        foreach ($view->subjects as $subject) {
            $data = [];

            foreach ($buckets as $bucket) {
                $bucketCount = $count[$subject->key][$bucket] ?? 0;
                $data[] = $bucketCount > 0 ? (int) round(($sum[$subject->key][$bucket] ?? 0) / $bucketCount) : null;
            }

            $datasets[] = [
                'label' => $subject->label(),
                'data' => $data,
                'backgroundColor' => $subject->color,
                'borderWidth' => 0,
                'borderRadius' => 4,
            ];
        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_BAR);
        $chart->setData([
            'labels' => array_map(static fn (int $bucket): string => (string) $bucket, $buckets),
            'datasets' => $datasets,
        ]);
        $chart->setOptions([
            'maintainAspectRatio' => false,
            'scales' => ['x' => ['grid' => ['display' => false]]],
            'plugins' => ['legend' => ['display' => true, 'position' => 'top']],
        ]);

        return $chart;
    }

    private function puzzlesChart(ComparisonView $view): Chart
    {
        $rows = array_slice($view->rows, 0, self::MAX_PUZZLES_IN_CHART);

        $labels = array_map(
            static fn ($row): string => mb_strlen($row->puzzleName) > 22
                ? mb_substr($row->puzzleName, 0, 21) . '…'
                : $row->puzzleName,
            $rows,
        );

        $datasets = [];

        foreach ($view->subjects as $subject) {
            $data = [];

            foreach ($rows as $row) {
                $value = null;

                foreach ($row->cells as $cell) {
                    if ($cell->subject->key === $subject->key && $cell->entry !== null) {
                        $value = $cell->entry->fastestTime;

                        break;
                    }
                }

                $data[] = $value;
            }

            $datasets[] = [
                'label' => $subject->label(),
                'data' => $data,
                'backgroundColor' => $subject->color,
                'borderWidth' => 0,
                'borderRadius' => 4,
            ];
        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_BAR);
        $chart->setData([
            'labels' => $labels,
            'datasets' => $datasets,
        ]);
        $chart->setOptions([
            'maintainAspectRatio' => false,
            'scales' => ['x' => ['grid' => ['display' => false]]],
            'plugins' => ['legend' => ['display' => true, 'position' => 'top']],
        ]);

        return $chart;
    }

    private function difficultyChart(ComparisonView $view): Chart
    {
        $tiers = DifficultyTier::cases();

        $sum = [];
        $count = [];

        foreach ($view->rows as $row) {
            if ($row->difficultyTier === null) {
                continue;
            }

            $tierValue = $row->difficultyTier->value;

            foreach ($row->cells as $cell) {
                if ($cell->entry === null) {
                    continue;
                }

                $sum[$cell->subject->key][$tierValue] = ($sum[$cell->subject->key][$tierValue] ?? 0) + $cell->entry->fastestTime;
                $count[$cell->subject->key][$tierValue] = ($count[$cell->subject->key][$tierValue] ?? 0) + 1;
            }
        }

        $datasets = [];

        foreach ($view->subjects as $subject) {
            $data = [];

            foreach ($tiers as $tier) {
                $tierCount = $count[$subject->key][$tier->value] ?? 0;
                $data[] = $tierCount > 0 ? (int) round(($sum[$subject->key][$tier->value] ?? 0) / $tierCount) : null;
            }

            $datasets[] = [
                'label' => $subject->label(),
                'data' => $data,
                'borderColor' => $subject->color,
                'backgroundColor' => $this->hexToRgba($subject->color, 0.15),
                'pointBackgroundColor' => $subject->color,
                'borderWidth' => 2,
                'fill' => true,
            ];
        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_RADAR);
        $chart->setData([
            'labels' => array_map(static fn (DifficultyTier $tier): string => $tier->name, $tiers),
            'datasets' => $datasets,
        ]);
        $chart->setOptions([
            'maintainAspectRatio' => false,
            'plugins' => ['legend' => ['display' => true, 'position' => 'top']],
        ]);

        return $chart;
    }

    private function hexToRgba(string $hex, float $alpha): string
    {
        $hex = ltrim($hex, '#');

        return sprintf(
            'rgba(%d, %d, %d, %s)',
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
            $alpha,
        );
    }
}
