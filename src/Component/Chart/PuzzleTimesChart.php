<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component\Chart;

use Nette\Utils\Strings;
use SpeedPuzzling\Web\Results\PuzzleSolver;
use SpeedPuzzling\Web\Results\PuzzleSolversGroup;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class PuzzleTimesChart
{
    public string|null $playerId = null;

    /**
     * @var array<array<PuzzleSolver|PuzzleSolversGroup>>
     */
    public array $results = [];

    public function __construct(
        readonly private ChartBuilderInterface $chartBuilder,
    ) {
    }

    public function getChart(): Chart
    {
        $labels = [];
        $chartData = [];
        $backgrounds = [];

        $rank = 0;
        foreach ($this->results as $i => $groupedResult) {
            $rank = $rank + 1;
            $result = $groupedResult[0];

            if ($result instanceof PuzzleSolver) {
                $labels[] = sprintf('%d. %s',
                    $rank,
                    Strings::truncate($result->playerName, 15),
                );

                if ($result->playerId === $this->playerId) {
                    $backgrounds[] = 'rgba(254, 64, 66, 1)';
                } else {
                    $backgrounds[] = 'rgba(254, 105, 106, 0.6)';
                }
            }

            if ($result instanceof PuzzleSolversGroup) {
                $isMe = false;
                $label = [];

                foreach ($result->players as $player) {
                    $label[] = Strings::truncate($player->playerName ?? '', 15);

                    if ($player->playerId === $this->playerId) {
                        $isMe = true;
                    }
                }

                $labels[] = implode("\n", $label);

                if ($isMe) {
                    $backgrounds[] = 'rgba(254, 64, 66, 1)';
                } else {
                    $backgrounds[] = 'rgba(254, 105, 106, 0.6)';
                }
            }

            $chartData[] = $result->time;

        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_LINE);
        $chart->setData([
            'labels' => $labels,
            'datasets' => [
                [
                    'backgroundColor' => $backgrounds,
                    'data' => $chartData,
                ],
            ],
        ]);

        $chart->setOptions([
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
            ],
        ]);

        return $chart;
    }
}
