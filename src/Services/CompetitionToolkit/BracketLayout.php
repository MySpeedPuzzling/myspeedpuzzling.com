<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use SpeedPuzzling\Web\Exceptions\InvalidAdvancementStep;
use SpeedPuzzling\Web\Results\AdvancementStepOverview;
use SpeedPuzzling\Web\Results\Bracket\Bracket;
use SpeedPuzzling\Web\Results\Bracket\BracketConnector;
use SpeedPuzzling\Web\Results\Bracket\BracketFeed;
use SpeedPuzzling\Web\Results\Bracket\BracketRound;
use SpeedPuzzling\Web\Value\AdvanceDistribution;
use SpeedPuzzling\Web\Value\AdvancementStepSettings;
use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * Lays an event's declared advancement steps out as brackets (competition toolkit A9, A-round-formats.md §3.5) - pure,
 * no database. One bracket per category, columns = stages (AdvancementStepGraph::stages(): the longest path, groups 1,
 * semifinals 2, final 3), the rounds of a stage in the event's schedule order, a line from every source round to every
 * target round it can send people to (`by_source`: only its own target), drawn between neighbouring stages.
 *
 * Only steps that work as declared count: a step a later round change broke (AdvancementStepGraph::validateStep()) is
 * left out - its organiser sees it marked on the steps page. Steps clashing with each other (a round fed twice, a cycle:
 * only in data written around the checks) give no bracket at all. Rounds no step names are not part of a bracket.
 */
final readonly class BracketLayout
{
    /**
     * @param list<AdvancementStepOverview> $steps
     * @param list<BracketRound> $rounds the event's rounds in schedule order
     * @return list<Bracket> by the category of the earliest round
     */
    public static function of(array $steps, array $rounds): array
    {
        $byId = [];
        $categories = [];
        $order = [];

        foreach ($rounds as $index => $round) {
            $id = strtolower($round->id);
            $byId[$id] = $round;
            $categories[$id] = $round->category;
            $order[$id] = $index;
        }

        /** @var array<string, AdvancementStepSettings> $valid */
        $valid = [];
        /** @var array<string, AdvancementStepOverview> $validSteps */
        $validSteps = [];

        foreach ($steps as $step) {
            try {
                AdvancementStepGraph::validateStep($step->settings, $categories);
            } catch (InvalidAdvancementStep) {
                continue;
            }

            $valid[$step->id] = $step->settings;
            $validSteps[$step->id] = $step;
        }

        if ($valid === []) {
            return [];
        }

        try {
            AdvancementStepGraph::validate($valid, $categories);
        } catch (InvalidAdvancementStep) {
            return [];
        }

        /** @var array<string, array<int, array<int, BracketRound>>> $stagesByCategory category => stage => schedule index => round */
        $stagesByCategory = [];

        foreach (AdvancementStepGraph::stages($valid) as $roundId => $stage) {
            $roundId = (string) $roundId;
            $round = $byId[$roundId];
            $stagesByCategory[$round->category->value][$stage][$order[$roundId]] = $round;
        }

        $feeds = [];
        foreach ($validSteps as $step) {
            $sourceNames = array_map(static fn (string $id): string => $byId[$id]->name, self::bySchedule($step->settings->sourceRoundIds, $order));

            foreach ($step->settings->targetRoundIds as $targetId) {
                $feeds[$targetId] = new BracketFeed($step, $sourceNames);
            }
        }

        $brackets = [];

        foreach ($stagesByCategory as $category => $stageRounds) {
            ksort($stageRounds);
            $stages = [];
            $first = PHP_INT_MAX;
            foreach ($stageRounds as $roundsOfStage) {
                ksort($roundsOfStage);
                $stages[] = array_values($roundsOfStage);
                $first = min($first, (int) array_key_first($roundsOfStage));
            }

            $brackets[] = [
                'first' => $first,
                'bracket' => new Bracket(
                    category: RoundCategory::from($category),
                    stages: $stages,
                    gaps: self::gaps($stages, $valid),
                    feeds: array_filter(
                        $feeds,
                        static fn (string $roundId): bool => isset($byId[$roundId]) && $byId[$roundId]->category->value === $category,
                        ARRAY_FILTER_USE_KEY,
                    ),
                ),
            ];
        }

        usort($brackets, static fn (array $a, array $b): int => $a['first'] <=> $b['first']);

        return array_map(static fn (array $item): Bracket => $item['bracket'], $brackets);
    }

    /**
     * @param list<list<BracketRound>> $stages
     * @param array<string, AdvancementStepSettings> $steps
     * @return list<list<BracketConnector>>
     */
    private static function gaps(array $stages, array $steps): array
    {
        $position = [];
        foreach ($stages as $stageIndex => $stage) {
            foreach ($stage as $index => $round) {
                $position[strtolower($round->id)] = [
                    'stage' => $stageIndex,
                    'y' => (int) round(($index + 0.5) / count($stage) * BracketConnector::HEIGHT),
                ];
            }
        }

        $gaps = array_fill(0, count($stages) - 1, []);

        foreach ($steps as $step) {
            foreach ($step->sourceRoundIds as $sourceId) {
                $targets = $step->distribution === AdvanceDistribution::BySource
                    ? [$step->targetBySource[$sourceId]]
                    : $step->targetRoundIds;

                foreach ($targets as $targetId) {
                    $from = $position[$sourceId] ?? null;
                    $to = $position[$targetId] ?? null;

                    // Lines are drawn between neighbouring stages only; a round feeding past a stage says so in its
                    // target's "from …" line
                    if ($from === null || $to === null || $to['stage'] !== $from['stage'] + 1) {
                        continue;
                    }

                    $gaps[$from['stage']][] = new BracketConnector($sourceId, $targetId, $from['y'], $to['y']);
                }
            }
        }

        return $gaps;
    }

    /**
     * @param list<string> $roundIds
     * @param array<string, int> $order
     * @return list<string>
     */
    private static function bySchedule(array $roundIds, array $order): array
    {
        $ids = array_values(array_filter($roundIds, static fn (string $id): bool => isset($order[$id])));
        usort($ids, static fn (string $a, string $b): int => $order[$a] <=> $order[$b]);

        return $ids;
    }
}
