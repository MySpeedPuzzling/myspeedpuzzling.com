<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\MspRating;

use SpeedPuzzling\Web\Results\RatingHistory;
use SpeedPuzzling\Web\Services\EventsPage\EventsPageDates;
use SpeedPuzzling\Web\Value\MspRatingTimeline;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

/**
 * The rating card's and the profile's two small charts (docs/features/msp-rating-history.md): the rating, and the place
 * on a reversed axis (#1 on top, so up is better on both). Day by day from the stored snapshots, the formula changes and
 * the next season's start as dashed lines (MspRatingTimeline, drawn by leaderboard_chart_controller.js). Tooltips and
 * ticks are written here as text (comparison_chart_controller.js) - nothing is formatted in the browser.
 */
readonly final class RatingHistoryChartFactory
{
    private const string LINE = '#1d6f6b';
    private const string FILL = 'rgba(29, 111, 107, 0.12)';
    private const string FORMULA_MARKER = '#b7791f';
    private const string SEASON_MARKER = '#6a5bd6';
    private const string INK = '#4b566b';
    private const string INK_MUTED = '#6b7488';
    private const string GRID = '#edf1f5';
    private const string TOOLTIP_BACKGROUND = 'rgba(55, 63, 80, 0.95)';

    public function __construct(
        private ChartBuilderInterface $chartBuilder,
        private TranslatorInterface $translator,
        private EventsPageDates $dates,
    ) {
    }

    /**
     * @return null|array{rating: Chart, place: null|Chart} null with fewer than two stored days; no place chart without
     *     two days on the ladder
     */
    public function charts(RatingHistory $history): null|array
    {
        if (count($history->days) < 2) {
            return null;
        }

        $labels = [];
        $ratings = [];
        $places = [];
        $ratingTooltips = [];
        $placeTooltips = [];

        foreach ($history->days as $day) {
            $title = $this->dates->format($day['date'], 'yMMMd');
            $labels[] = $this->dates->format($day['date'], 'MMMd');
            $ratings[] = $day['rating'];
            $places[] = $day['place'];
            $ratingTooltips[] = ['title' => $title, 'lines' => [$this->translator->trans('msp_rating.chart.rating_tooltip', ['%rating%' => $day['rating']])]];
            $placeTooltips[] = ['title' => $title, 'lines' => [$day['place'] !== null
                ? $this->translator->trans('msp_rating.chart.place_tooltip', ['%place%' => $day['place']])
                : $this->translator->trans('msp_rating.chart.not_on_ladder')]];
        }

        $markers = $this->markers($history);

        [$ratingMin, $ratingMax, $ratingTicks] = self::niceTicks(min($ratings) - 5, max($ratings) + 5, 3);
        $rating = $this->line($labels, $ratings, [$ratingMin, $ratingMax], $ratingTicks, false, $ratingTooltips, $markers);

        $knownPlaces = array_values(array_filter($places, static fn (null|int $place): bool => $place !== null));
        $place = null;

        if (count($knownPlaces) >= 2) {
            [, $placeMax, $placeTicks] = self::niceTicks(1, max($knownPlaces), 3);
            $placeMin = max(1, min($knownPlaces) - 1);
            $placeTicks = array_values(array_filter($placeTicks, static fn (int $tick): bool => $tick >= $placeMin));
            $place = $this->line($labels, $places, [$placeMin, $placeMax], $placeTicks, true, $placeTooltips, $markers);
        }

        return ['rating' => $rating, 'place' => $place];
    }

    /**
     * @return list<array{kind: string, position: float, label: string, color: string, dashed: bool}>
     */
    private function markers(RatingHistory $history): array
    {
        $first = $history->days[0]['date'];
        $last = $history->days[count($history->days) - 1]['date'];
        $markers = [];

        foreach (MspRatingTimeline::markersBetween($first, $last) as $marker) {
            foreach ($history->days as $index => $day) {
                if ($day['date']->format('Y-m-d') !== $marker['date']->format('Y-m-d')) {
                    continue;
                }

                // Bar units: day i is centred on i + 0.5 (leaderboard_chart_controller.js drawVerticalMarkers())
                $markers[] = [
                    'kind' => 'vertical',
                    'position' => $index + 0.5,
                    'label' => $marker['kind'] === 'season'
                        ? $this->translator->trans('msp_rating.chart.season_marker', ['%season%' => $marker['key']])
                        : $this->translator->trans('msp_rating.chart.formula_marker'),
                    'color' => $marker['kind'] === 'season' ? self::SEASON_MARKER : self::FORMULA_MARKER,
                    'dashed' => true,
                ];
            }
        }

        return $markers;
    }

    /**
     * @param list<string> $labels
     * @param list<null|int> $data
     * @param array{0: int, 1: int} $range
     * @param list<int> $ticks
     * @param list<array{title: string, lines: list<string>}> $tooltips
     * @param list<array{kind: string, position: float, label: string, color: string, dashed: bool}> $markers
     */
    private function line(array $labels, array $data, array $range, array $ticks, bool $reverse, array $tooltips, array $markers): Chart
    {
        $chart = $this->chartBuilder->createChart(Chart::TYPE_LINE);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [[
                'data' => $data,
                'borderColor' => self::LINE,
                'backgroundColor' => self::FILL,
                'fill' => $reverse ? 'end' : 'start',
                'borderWidth' => 2,
                'pointRadius' => 0,
                'pointHoverRadius' => 4,
                'pointHitRadius' => 6,
                'spanGaps' => false,
            ]],
        ]);
        $chart->setOptions([
            'maintainAspectRatio' => false,
            'animation' => false,
            // Room above the plot for the markers' labels
            'layout' => ['padding' => ['top' => $markers !== [] ? 16 : 4]],
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'scales' => [
                'x' => [
                    'grid' => ['display' => false],
                    'border' => ['color' => self::GRID],
                    'ticks' => ['color' => self::INK_MUTED, 'font' => ['size' => 11], 'maxRotation' => 0, 'autoSkip' => true, 'autoSkipPadding' => 16],
                ],
                'y' => [
                    'type' => 'linear',
                    'reverse' => $reverse,
                    'min' => $range[0],
                    'max' => $range[1],
                    'grid' => ['color' => self::GRID, 'drawTicks' => false],
                    'border' => ['display' => false],
                    'ticks' => ['color' => self::INK_MUTED, 'font' => ['size' => 11], 'padding' => 6],
                ],
            ],
            'plugins' => [
                'legend' => ['display' => false],
                'tooltip' => [
                    'backgroundColor' => self::TOOLTIP_BACKGROUND,
                    'padding' => 8,
                    'boxPadding' => 4,
                    'displayColors' => false,
                ],
                'comparisonChart' => [
                    'tooltips' => [$tooltips],
                    'ticks' => ['y' => array_map(static fn (int $tick): array => [$tick, $reverse ? '#' . $tick : (string) $tick], $ticks)],
                    'labelColor' => self::INK,
                ],
                'leaderboardMarkers' => ['markers' => $markers],
            ],
        ]);

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
}
