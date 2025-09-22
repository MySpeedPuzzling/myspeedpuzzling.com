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
    public null|string $playerId = null;

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
        $i = 0;
        $lastKey = null;
        foreach ($this->results as $key => $groupedResult) {
            $i++;
            $result = $groupedResult[0];

            if ($lastKey === null || $result->time !== $this->results[$lastKey][0]->time) {
                $rank = $i;
            }

            if ($result instanceof PuzzleSolver) {
                $labels[] = sprintf(
                    '%d. %s',
                    $rank,
                    Strings::truncate($result->playerName ?? $result->playerCode, 15),
                );

                if ($this->playerId !== null && $result->playerId === $this->playerId) {
                    $backgrounds[] = 'rgba(254, 64, 66, 1)';
                } elseif ($result->firstAttempt === true) {
                    $backgrounds[] = 'rgba(105, 179, 254, 0.6)';
                } else {
                    $backgrounds[] = 'rgba(254, 105, 106, 0.6)';
                }
            }

            if ($result instanceof PuzzleSolversGroup) {
                $isMe = false;
                $label = [];

                foreach ($result->players as $player) {
                    $label[] = Strings::truncate($player->playerName ?? $player->playerCode ?? '', 15);

                    if ($this->playerId !== null && $player->playerId === $this->playerId) {
                        $isMe = true;
                    }
                }

                $labels[] = implode("\n", $label);

                if ($isMe) {
                    $backgrounds[] = 'rgba(254, 64, 66, 1)';
                } elseif ($result->firstAttempt === true) {
                    $backgrounds[] = 'rgba(105, 179, 254, 0.6)';
                } else {
                    $backgrounds[] = 'rgba(254, 105, 106, 0.6)';
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
