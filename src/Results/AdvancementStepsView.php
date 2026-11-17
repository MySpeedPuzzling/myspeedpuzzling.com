<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Exceptions\InvalidAdvancementStep;
use SpeedPuzzling\Web\Services\CompetitionToolkit\AdvancementStepGraph;
use SpeedPuzzling\Web\Value\AdvancementStepSettings;
use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * An event's advancement steps with their rounds' names, each step's problem (a step a later round change broke: a
 * pruned target, a round of another category now) and the stages of the rounds (competition toolkit A8) - for the steps
 * page, the hub card and the results desk's banner.
 */
readonly final class AdvancementStepsView
{
    /**
     * @param list<array{step: AdvancementStepOverview, label: string, sources: list<string>, targets: list<string>, map: list<array{string, string}>, problem: null|string}> $items
     * @param list<list<string>> $stages round names per stage (1 first), empty while any step is broken
     */
    private function __construct(
        public array $items,
        public array $stages,
    ) {
    }

    /**
     * @param list<AdvancementStepOverview> $steps
     * @param array<CompetitionRoundForManagement|OfficialResultsRound> $rounds the event's rounds, in the event's order
     */
    public static function of(array $steps, array $rounds): self
    {
        $names = [];
        $categories = [];

        foreach ($rounds as $round) {
            if ($round instanceof OfficialResultsRound) {
                $names[$round->id()] = $round->overview->name;
                $categories[$round->id()] = RoundCategory::from($round->overview->category);
            } else {
                $names[strtolower($round->id)] = $round->name;
                $categories[strtolower($round->id)] = $round->category;
            }
        }

        $name = static fn (string $roundId): string => $names[$roundId] ?? '?';
        $items = [];
        $valid = [];

        foreach ($steps as $step) {
            $problem = null;

            try {
                AdvancementStepGraph::validateStep($step->settings, $categories);
                $valid[$step->id] = $step->settings;
            } catch (InvalidAdvancementStep $broken) {
                $problem = $broken->translationKey();
            }

            $sources = array_map($name, array_values(array_filter($step->settings->sourceRoundIds, static fn (string $id): bool => isset($names[$id]))));
            $targets = array_map($name, array_values(array_filter($step->settings->targetRoundIds, static fn (string $id): bool => isset($names[$id]))));
            $map = [];

            foreach ($step->settings->targetBySource as $source => $target) {
                $map[] = [$name((string) $source), $name($target)];
            }

            $items[] = [
                'step' => $step,
                'label' => $step->name ?? (implode(', ', $sources) . ' → ' . implode(', ', $targets)),
                'sources' => $sources,
                'targets' => $targets,
                'map' => $map,
                'problem' => $problem,
            ];
        }

        return new self($items, count($valid) === count($steps) ? self::stagesOf($valid, $names, $categories) : []);
    }

    /**
     * The steps whose sources include this round and that work as declared - the results desk's banner.
     *
     * @return list<array{step: AdvancementStepOverview, label: string, sources: list<string>, targets: list<string>, map: list<array{string, string}>, problem: null|string}>
     */
    public function forSourceRound(string $roundId): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (array $item): bool => $item['problem'] === null && $item['step']->hasSource($roundId),
        ));
    }

    /**
     * @param array<string, AdvancementStepSettings> $steps
     * @param array<string, string> $names
     * @param array<string, RoundCategory> $categories
     * @return list<list<string>>
     */
    private static function stagesOf(array $steps, array $names, array $categories): array
    {
        try {
            AdvancementStepGraph::validate($steps, $categories);
        } catch (InvalidAdvancementStep) {
            // A clash between steps (a round fed twice, a cycle) - only possible in data written around the checks
            return [];
        }

        $stages = [];
        $order = array_flip(array_keys($names));

        foreach (AdvancementStepGraph::stages($steps) as $roundId => $stage) {
            $stages[$stage][$order[$roundId] ?? PHP_INT_MAX] = $names[$roundId] ?? '?';
        }

        ksort($stages);

        return array_values(array_map(static function (array $roundNames): array {
            ksort($roundNames);

            return array_values($roundNames);
        }, $stages));
    }
}
