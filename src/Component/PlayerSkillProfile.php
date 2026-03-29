<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\GetPlayerBaselineProgress;
use SpeedPuzzling\Web\Query\GetPlayerSkill;
use SpeedPuzzling\Web\Query\GetPlayerSkillHistory;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
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

    public null|int $currentBaselineSeconds = null;

    public null|int $targetBaselineSeconds = null;

    /** @var list<int> */
    public array $availablePieceCounts = [];

    /**
     * @var array<int, array{baseline_solves: int, qualifying_puzzles: int}>
     */
    public array $progress = [];

    public function __construct(
        readonly private GetPlayerSkill $getPlayerSkill,
        readonly private GetPlayerSkillHistory $getPlayerSkillHistory,
        readonly private GetPlayerBaselineProgress $getPlayerBaselineProgress,
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

        if ($this->currentSkill === null && $this->skills !== []) {
            $this->currentSkill = $this->skills[0];
            $this->selectedPiecesCount = $this->currentSkill->piecesCount;
        }

        $this->improvementChart = $this->buildImprovementChart();
        $this->computeBaselineAndTarget();

        if ($this->skills === []) {
            $allProgress = $this->getPlayerBaselineProgress->solveProgress($this->playerId);
            $this->progress = array_filter(
                $allProgress,
                static fn (array $data, int $pc): bool => in_array($pc, PuzzleIntelligenceRecalculator::SKILL_PIECES_COUNTS, true),
                ARRAY_FILTER_USE_BOTH,
            );
        }
    }

    #[LiveAction]
    public function changePiecesCount(#[LiveArg] int $piecesCount): void
    {
        if (in_array($piecesCount, $this->availablePieceCounts, true)) {
            $this->selectedPiecesCount = $piecesCount;
        }
    }

    private function computeBaselineAndTarget(): void
    {
        if ($this->currentSkill === null) {
            return;
        }

        $this->currentBaselineSeconds = $this->getPlayerBaselineProgress->currentBaseline(
            $this->playerId,
            $this->selectedPiecesCount,
        );

        $nextTier = $this->currentSkill->skillTier->nextTier();

        if ($nextTier === null || $this->currentBaselineSeconds === null) {
            return;
        }

        $this->targetBaselineSeconds = $this->getPlayerBaselineProgress->baselineAtPercentile(
            $this->selectedPiecesCount,
            $nextTier->minimumPercentile(),
        );
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
                    'label' => 'Average solving time (min)',
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
}
