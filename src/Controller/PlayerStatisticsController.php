<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use DateTimeImmutable;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Services\ComputeStatistics;
use SpeedPuzzling\Web\Value\Month;
use SpeedPuzzling\Web\Value\Statistics\OverallStatistics;
use SpeedPuzzling\Web\Value\Statistics\PerCategoryStatistics;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

final class PlayerStatisticsController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private ComputeStatistics $computeStatistics,
        readonly private GetPlayerSolvedPuzzles $getPlayerSolvedPuzzles,
        readonly private TranslatorInterface $translator,
        readonly private ChartBuilderInterface $chartBuilder,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/statistiky-hrace/{playerId}',
            'en' => '/en/player-statistics/{playerId}',
        ],
        name: 'player_statistics',
    )]
    public function __invoke(
        Request $request,
        string $playerId,
        #[CurrentUser] UserInterface|null $user,
    ): Response
    {
        try {
            $player = $this->getPlayerProfile->byId($playerId);
        } catch (PlayerNotFound) {
            $this->addFlash('primary', $this->translator->trans('flashes.player_not_found'));

            return $this->redirectToRoute('players');
        }

        $year = $request->query->getInt('year');
        $month = $request->query->getInt('month');

        $months = [];
        $years = [];
        $firstResultDate = $this->getPlayerSolvedPuzzles->getOldestResultDate($playerId);

        if ($firstResultDate !== null) {
            $months = $this->getMonthsSinceDate($firstResultDate);
            $years = $this->getYearsSinceDate($firstResultDate);
        }

        [$dateFrom, $dateTo] = $this->calculateDates($month, $year);

        $activeShowAll = $request->query->getBoolean('show-all');

        if ($activeShowAll === true) {
            $dateFrom = $firstResultDate ?? $dateFrom;
        }

        [$overallStatistics, $soloStatistics, $duoStatistics, $teamStatistics] = $this->computeStatistics->forPlayer(
            playerId: $playerId,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
        );

        $activeYear = $year > 0 ? $year : null;

        if ($month === 0 && $year === 0 && $activeShowAll === false) {
            $now = new DateTimeImmutable();
            $month = (int) $now->format('m');
            $year = (int) $now->format('Y');
        }

        $activeMonth = new Month($month, $year);


        return $this->render('player_statistics.html.twig', [
            'player' => $player,
            'solo_statistics' => $soloStatistics,
            'duo_statistics' => $duoStatistics,
            'team_statistics' => $teamStatistics,
            'overall_statistics' => $overallStatistics,
            'solo_manufacturers_chart' => $this->getManufacturersChart($soloStatistics),
            'duo_manufacturers_chart' => $this->getManufacturersChart($duoStatistics),
            'team_manufacturers_chart' => $this->getManufacturersChart($teamStatistics),
            'overall_manufacturers_chart' => $this->getManufacturersChart($overallStatistics),
            'overall_pieces_chart' => $this->getPiecesChart($overallStatistics),
            'solo_pieces_chart' => $this->getPiecesChart($soloStatistics),
            'duo_pieces_chart' => $this->getPiecesChart($duoStatistics),
            'team_pieces_chart' => $this->getPiecesChart($teamStatistics),
            'overall_puzzling_time_chart' => $this->getPuzzlingTimeChart($overallStatistics, $dateFrom, $dateTo),
            'solo_puzzling_time_chart' => $this->getPuzzlingTimeChart($soloStatistics, $dateFrom, $dateTo),
            'duo_puzzling_time_chart' => $this->getPuzzlingTimeChart($duoStatistics, $dateFrom, $dateTo),
            'team_puzzling_time_chart' => $this->getPuzzlingTimeChart($teamStatistics, $dateFrom, $dateTo),
            'available_months' => $months,
            'available_years' => $years,
            'active_year' => $activeYear,
            'active_month' => $activeMonth,
            'active_show_all' => $activeShowAll,
        ]);
    }

    /**
     * @return array<Month>
     */
    private function getMonthsSinceDate(DateTimeImmutable $sinceDate): array
    {
        $currentDate = new DateTimeImmutable('first day of this month');
        $months = [];

        $iteratorDate = $currentDate;

        while ($iteratorDate >= $sinceDate->modify('first day of this month')) {
            $months[] = new Month((int) $iteratorDate->format('m'), (int) $iteratorDate->format('Y'));
            $iteratorDate = $iteratorDate->modify('-1 month');
        }

        return $months;
    }

    /**
     * @return array<int>
     */
    private function getYearsSinceDate(DateTimeImmutable $sinceDate): array
    {
        $currentYear = (int) (new DateTimeImmutable())->format('Y');
        $sinceYear = (int) $sinceDate->format('Y');

        $years = [];

        for ($year = $currentYear; $year >= $sinceYear; $year--) {
            $years[] = $year;
        }

        return $years;
    }

    /**
     * @return array<DateTimeImmutable>
     */
    private function calculateDates(int $month, int $year): array
    {
        $currentDate = new DateTimeImmutable();
        $currentYear = (int) $currentDate->format('Y');
        $currentMonth = (int) $currentDate->format('m');

        $isYearValid = $year >= 2015 && $year <= $currentYear;
        $isMonthValid = $month >= 1 && $month <= 12;

        if ($isYearValid && $isMonthValid) {
            // Both year and month provided and valid
            $dateFrom = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
            $dateTo = $dateFrom->modify('last day of this month')->setTime(23, 59, 59);
        } elseif ($isYearValid) {
            // Only year provided and valid
            $dateFrom = new DateTimeImmutable(sprintf('%04d-01-01', $year));
            $dateTo = new DateTimeImmutable(sprintf('%04d-12-31 23:59:59', $year));
        } else {
            // Neither provided nor invalid, use current month and year
            $dateFrom = new DateTimeImmutable(sprintf('%04d-%02d-01', $currentYear, $currentMonth));
            $dateTo = $dateFrom->modify('last day of this month')->setTime(23, 59, 59);
        }

        return [$dateFrom, $dateTo];
    }

    private function getManufacturersChart(OverallStatistics|PerCategoryStatistics $statistics): Chart
    {
        $labels = [];
        $chartData = [];

        foreach ($statistics->solvedPuzzle->countPerManufacturer as $manufacturerName => $count) {
            $chartData[] = $count;
            $labels[] = sprintf('%s (%dx)', $manufacturerName, $count);
        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_DOUGHNUT);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [
                [
                    'data' => $chartData,
                ],
            ],
        ]);

        $chart->setOptions([
            'responsive' => true,
            'aspectRatio' => 2,
        ]);

        return $chart;
    }

    private function getPiecesChart(OverallStatistics|PerCategoryStatistics $statistics): Chart
    {
        $labels = [];
        $chartData = [];

        if ($statistics instanceof PerCategoryStatistics) {
            foreach ($statistics->perPieces as $piecesStatistics) {
                $chartData[] = $piecesStatistics->count;
                $labels[] = sprintf('%d pieces (%dx)', $piecesStatistics->pieces, $piecesStatistics->count);
            }
        }

        if ($statistics instanceof OverallStatistics) {
            foreach ($statistics->perPiecesCount as $pieces => $count) {
                $chartData[] = $count;
                $labels[] = sprintf('%d pieces (%dx)', $pieces, $count);
            }
        }


        $chart = $this->chartBuilder->createChart(Chart::TYPE_DOUGHNUT);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [
                [
                    'data' => $chartData,
                ],
            ],
        ]);

        $chart->setOptions([
            'responsive' => true,
            'aspectRatio' => 2,
        ]);

        return $chart;
    }

    private function getPuzzlingTimeChart(
        OverallStatistics|PerCategoryStatistics $statistics,
        DateTimeImmutable $dateFrom,
        DateTimeImmutable $dateTo,
    ): Chart {
        $labels = [];
        $chartData = [];
        $intervalDays = $dateTo->diff($dateFrom)->days;

            // Group by month if the interval is greater than 31 days
        if ($intervalDays > 31) {
            $monthIterator = $dateFrom->modify('first day of this month');

            while ($monthIterator <= $dateTo) {
                $monthKey = $monthIterator->format('m/y');
                $monthStart = $monthIterator->modify('first day of this month');
                $monthEnd = $monthIterator->modify('last day of this month');

                $monthSum = 0;
                for ($day = $monthStart; $day <= $monthEnd && $day <= $dateTo; $day = $day->modify('+1 day')) {
                    $dayKey = $day->format('Y-m-d');
                    $monthSum += $statistics->timeSpentSolving->perDay[$dayKey] ?? 0;
                }

                $labels[] = $monthKey;
                $chartData[] = $monthSum;

                $monthIterator = $monthIterator->modify('+1 month');
            }
        } else {
            // Daily grouping for intervals of 31 days or fewer
            for ($day = $dateFrom; $day <= $dateTo; $day = $day->modify('+1 day')) {
                $dayKey = $day->format('Y-m-d');
                $labels[] = $day->format('d.m.');
                $chartData[] = $statistics->timeSpentSolving->perDay[$dayKey] ?? 0;
            }
        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_BAR);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [
                [
                    'data' => $chartData,
                    'borderColor' => '#fe4042',
                    'borderWidth' => 1,
                    'backgroundColor' => 'rgba(254, 64, 66, 0.2)',
                    'fill' => true,
                    'cubicInterpolationMode' => 'monotone',
                    'tension' => 0.4,
                ],
            ],
        ]);

        $chart->setOptions([
            'responsive' => true,
            'scales' => [
                'x' => [
                    'grid' => [
                        'display' => false,
                    ],
                ],
            ],
            'plugins' => [
                'legend' => [
                    'display' => false,
                ],
            ],
        ]);

        return $chart;
    }
}
