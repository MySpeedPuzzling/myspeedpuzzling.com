<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetPlayerStatistics;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
        readonly private GetPlayerStatistics $getPlayerStatistics,
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
    public function __invoke(string $playerId, #[CurrentUser] UserInterface|null $user): Response
    {
        try {
            $player = $this->getPlayerProfile->byId($playerId);
        } catch (PlayerNotFound) {
            $this->addFlash('primary', $this->translator->trans('flashes.player_not_found'));

            return $this->redirectToRoute('players');
        }

        $soloStatistics = $this->getPlayerStatistics->solo($playerId);
        $duoStatistics = $this->getPlayerStatistics->duo($playerId);
        $teamStatistics = $this->getPlayerStatistics->team($playerId);
        $overallStatistics = $soloStatistics
            ->sum($duoStatistics)
            ->sum($teamStatistics);

        return $this->render('player_statistics.html.twig', [
            'player' => $player,
            'solo_statistics' => $soloStatistics,
            'duo_statistics' => $duoStatistics,
            'team_statistics' => $teamStatistics,
            'overall_statistics' => $overallStatistics,
            'manufacturers_chart' => $this->getManufacturersChart(),
            'pieces_chart' => $this->getPiecesChart(),
            'puzzling_time_chart' => $this->getPuzzlingTimeChart(),
        ]);
    }

    private function getManufacturersChart(): Chart
    {
        $labels = [];
        $chartData = [];

        $chartData[] = 40;
        $labels[] = 'Ravensburger (40x)';

        $chartData[] = 10;
        $labels[] = 'Trefl (10x)';

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
        ]);

        return $chart;
    }

    private function getPiecesChart(): Chart
    {
        $labels = [];
        $chartData = [];

        $chartData[] = 40;
        $labels[] = '500 pieces (30x)';

        $chartData[] = 10;
        $labels[] = '400 pieces (10x)';

        $chartData[] = 10;
        $labels[] = '200 pieces (5x)';

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
        ]);

        return $chart;
    }

    private function getPuzzlingTimeChart(): Chart
    {
        $labels = [];
        $chartData = [];

        $chartData[] = 3000;
        $labels[] = '1.2.';

        $chartData[] = 1000;
        $labels[] = '2.2.';

        $chartData[] = 2000;
        $labels[] = '3.2.';

        $chartData[] = 1000;
        $labels[] = '4.2.';

        $chartData[] = 2000;
        $labels[] = '5.2.';

        $chartData[] = 0;
        $labels[] = '6.2.';

        $chartData[] = 0;
        $labels[] = '7.2.';

        $chartData[] = 1800;
        $labels[] = '8.2.';

        $chart = $this->chartBuilder->createChart(Chart::TYPE_LINE);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [
                [
                    'data' => $chartData,
                    'borderColor' => '#fe4042',
                    'borderWidth' => 2,
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
