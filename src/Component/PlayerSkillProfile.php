<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\GetPlayerSkill;
use SpeedPuzzling\Web\Query\GetPlayerSkillHistory;
use SpeedPuzzling\Web\Query\GetSkillDistribution;
use SpeedPuzzling\Web\Results\PlayerSkillHistoryPoint;
use SpeedPuzzling\Web\Results\PlayerSkillResult;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsLiveComponent]
final class PlayerSkillProfile
{
    use DefaultActionTrait;

    #[LiveProp]
    public string $playerId = '';

    #[LiveProp(writable: true)]
    public int $selectedPiecesCount = 500;

    /** @var list<PlayerSkillResult> */
    public array $skills = [];

    public null|PlayerSkillResult $currentSkill = null;

    public null|Chart $improvementChart = null;

    public null|Chart $distributionChart = null;

    /** @var list<int> */
    public array $availablePieceCounts = [];

    public function __construct(
        readonly private GetPlayerSkill $getPlayerSkill,
        readonly private GetPlayerSkillHistory $getPlayerSkillHistory,
        readonly private GetSkillDistribution $getSkillDistribution,
        readonly private ChartBuilderInterface $chartBuilder,
    ) {
    }

    #[PostMount]
    #[PreReRender]
    public function populate(): void
    {
        $this->skills = $this->getPlayerSkill->byPlayerId($this->playerId);

        $this->availablePieceCounts = array_map(
            static fn (PlayerSkillResult $s): int => $s->piecesCount,
            $this->skills,
        );

        $this->currentSkill = null;

        foreach ($this->skills as $skill) {
            if ($skill->piecesCount === $this->selectedPiecesCount) {
                $this->currentSkill = $skill;
                break;
            }
        }

        // If selected piece count has no skill, try the first available
        if ($this->currentSkill === null && $this->skills !== []) {
            $this->currentSkill = $this->skills[0];
            $this->selectedPiecesCount = $this->currentSkill->piecesCount;
        }

        $this->improvementChart = $this->buildImprovementChart();
        $this->distributionChart = $this->buildDistributionChart();
    }

    #[LiveAction]
    public function changePiecesCount(#[LiveArg] int $piecesCount): void
    {
        if (in_array($piecesCount, $this->availablePieceCounts, true)) {
            $this->selectedPiecesCount = $piecesCount;
        }
    }

    private function buildImprovementChart(): null|Chart
    {
        $history = $this->getPlayerSkillHistory->byPlayerId($this->playerId, $this->selectedPiecesCount);

        if (count($history) < 2) {
            return null;
        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_LINE);

        $labels = array_map(
            static fn (PlayerSkillHistoryPoint $point): string => $point->month->format('M Y'),
            $history,
        );

        $data = array_map(
            static fn (PlayerSkillHistoryPoint $point): float => round($point->baselineSeconds / 60, 1),
            $history,
        );

        $chart->setData([
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => $this->selectedPiecesCount . 'pc baseline (minutes)',
                    'data' => $data,
                    'borderColor' => '#0d6efd',
                    'backgroundColor' => 'rgba(13, 110, 253, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
                    'pointRadius' => 4,
                ],
            ],
        ]);

        $chart->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => false,
            'scales' => [
                'y' => [
                    'reverse' => true,
                    'title' => [
                        'display' => true,
                        'text' => 'Minutes',
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

    private function buildDistributionChart(): null|Chart
    {
        $distribution = $this->getSkillDistribution->forPiecesCount($this->selectedPiecesCount, $this->playerId);

        if ($distribution['labels'] === []) {
            return null;
        }

        $chart = $this->chartBuilder->createChart(Chart::TYPE_BAR);

        // Color each bar — highlight the player's bucket
        $backgroundColors = [];
        $borderColors = [];

        foreach ($distribution['counts'] as $i => $count) {
            if ($distribution['player_bucket'] !== null && $i === $distribution['player_bucket']) {
                $backgroundColors[] = 'rgba(13, 110, 253, 0.8)';
                $borderColors[] = '#0d6efd';
            } else {
                $backgroundColors[] = 'rgba(108, 117, 125, 0.3)';
                $borderColors[] = 'rgba(108, 117, 125, 0.5)';
            }
        }

        $chart->setData([
            'labels' => $distribution['labels'],
            'datasets' => [
                [
                    'label' => 'Players',
                    'data' => $distribution['counts'],
                    'backgroundColor' => $backgroundColors,
                    'borderColor' => $borderColors,
                    'borderWidth' => 1,
                ],
            ],
        ]);

        $chart->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => false,
            'scales' => [
                'x' => [
                    'title' => [
                        'display' => true,
                        'text' => 'Skill Score',
                    ],
                ],
                'y' => [
                    'title' => [
                        'display' => true,
                        'text' => 'Players',
                    ],
                    'beginAtZero' => true,
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
