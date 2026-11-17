<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CompetitionToolkit;

use SpeedPuzzling\Web\Entity\CompetitionAdvancementStep;
use SpeedPuzzling\Web\Exceptions\InvalidAdvancementStep;
use SpeedPuzzling\Web\Value\AdvanceDistribution;
use SpeedPuzzling\Web\Value\AdvancementStepSettings;
use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * The rules of an event's advancement steps (competition toolkit A8, A-round-formats.md §3.5) - pure, no database.
 *
 * One step: at least one source and one target round, all rounds of the event and of one category, no round both a
 * source and a target, a distribution that fits the targets (`single` = exactly one, `balanced` = two or more,
 * `by_source` = every source mapped onto one of the targets), counts in range.
 * All steps together: a round is the target of one step at most ("a round is fed once") and the rounds + steps form no
 * cycle. The stage of a round = the longest path to it from a round no step feeds (groups 1, semifinals 2, final 3).
 */
final readonly class AdvancementStepGraph
{
    /**
     * @param AdvancementStepSettings $step normalised
     * @param array<string, RoundCategory> $roundCategories the event's rounds: lower-cased id => category
     * @throws InvalidAdvancementStep
     */
    public static function validateStep(AdvancementStepSettings $step, array $roundCategories): void
    {
        if ($step->sourceRoundIds === []) {
            throw new InvalidAdvancementStep('no_sources');
        }

        if ($step->targetRoundIds === []) {
            throw new InvalidAdvancementStep('no_targets');
        }

        if (count($step->sourceRoundIds) > CompetitionAdvancementStep::MAX_ROUNDS || count($step->targetRoundIds) > CompetitionAdvancementStep::MAX_ROUNDS) {
            throw new InvalidAdvancementStep('too_many_rounds');
        }

        foreach ($step->roundIds() as $roundId) {
            if (!isset($roundCategories[$roundId])) {
                throw new InvalidAdvancementStep('unknown_round');
            }
        }

        if (array_intersect($step->sourceRoundIds, $step->targetRoundIds) !== []) {
            throw new InvalidAdvancementStep('source_is_target');
        }

        $categories = array_unique(array_map(static fn (string $roundId): string => $roundCategories[$roundId]->value, $step->roundIds()));

        if (count($categories) > 1) {
            throw new InvalidAdvancementStep('mixed_categories');
        }

        $targets = count($step->targetRoundIds);

        if ($step->distribution === AdvanceDistribution::Single && $targets !== 1) {
            throw new InvalidAdvancementStep('single_needs_one_target');
        }

        if ($step->distribution === AdvanceDistribution::Balanced && $targets < 2) {
            throw new InvalidAdvancementStep('balanced_needs_two_targets');
        }

        if ($step->distribution === AdvanceDistribution::BySource) {
            foreach ($step->sourceRoundIds as $sourceId) {
                if (!in_array($step->targetBySource[$sourceId] ?? null, $step->targetRoundIds, true)) {
                    throw new InvalidAdvancementStep('by_source_incomplete');
                }
            }
        }

        if ($step->qualifyCount !== null && ($step->qualifyCount < 1 || $step->qualifyCount > AdvancementStepSettings::QUALIFY_MAX)) {
            throw new InvalidAdvancementStep('invalid_qualify_count');
        }

        if ($step->bestOfEachCountry !== null && ($step->bestOfEachCountry < 1 || $step->bestOfEachCountry > AdvancementStepSettings::COUNTRY_MAX)) {
            throw new InvalidAdvancementStep('invalid_country_count');
        }
    }

    /**
     * @param array<string, AdvancementStepSettings> $steps every step of the event as it would be saved: step id => settings (normalised)
     * @param array<string, RoundCategory> $roundCategories the event's rounds: lower-cased id => category
     * @throws InvalidAdvancementStep
     */
    public static function validate(array $steps, array $roundCategories): void
    {
        $fedBy = [];

        foreach ($steps as $stepId => $step) {
            self::validateStep($step, $roundCategories);

            foreach ($step->targetRoundIds as $targetId) {
                if (isset($fedBy[$targetId]) && $fedBy[$targetId] !== (string) $stepId) {
                    throw new InvalidAdvancementStep('round_fed_twice');
                }

                $fedBy[$targetId] = (string) $stepId;
            }
        }

        if (self::hasCycle($steps)) {
            throw new InvalidAdvancementStep('cycle');
        }
    }

    /**
     * The stage of every round taking part in a step: 1 = fed by no step, else 1 + the deepest of the rounds feeding it.
     *
     * @param array<string, AdvancementStepSettings> $steps a valid graph (validate())
     * @return array<string, int> round id => stage
     */
    public static function stages(array $steps): array
    {
        $feeders = self::feeders($steps);
        $stages = [];

        $stageOf = static function (string $roundId, array $visiting) use (&$stageOf, &$stages, $feeders): int {
            if (isset($stages[$roundId])) {
                return $stages[$roundId];
            }

            $deepest = 0;
            foreach ($feeders[$roundId] ?? [] as $sourceId) {
                // A cycle never passes validate(); guarded anyway so a broken graph cannot loop
                if (!isset($visiting[$sourceId])) {
                    $deepest = max($deepest, $stageOf($sourceId, $visiting + [$roundId => true]));
                }
            }

            return $stages[$roundId] = $deepest + 1;
        };

        foreach ($steps as $step) {
            foreach ($step->roundIds() as $roundId) {
                $stageOf($roundId, [$roundId => true]);
            }
        }

        return $stages;
    }

    /**
     * @param array<string, AdvancementStepSettings> $steps
     * @return array<string, list<string>> target round id => the source rounds feeding it
     */
    private static function feeders(array $steps): array
    {
        $feeders = [];

        foreach ($steps as $step) {
            foreach ($step->targetRoundIds as $targetId) {
                foreach ($step->sourceRoundIds as $sourceId) {
                    $feeders[$targetId][] = $sourceId;
                }
            }
        }

        return $feeders;
    }

    /**
     * @param array<string, AdvancementStepSettings> $steps
     */
    private static function hasCycle(array $steps): bool
    {
        $next = [];
        foreach ($steps as $step) {
            foreach ($step->sourceRoundIds as $sourceId) {
                foreach ($step->targetRoundIds as $targetId) {
                    $next[$sourceId][] = $targetId;
                }
            }
        }

        // 1 = on the current path, 2 = done
        $state = [];

        $visit = static function (string $roundId) use (&$visit, &$state, $next): bool {
            $state[$roundId] = 1;

            foreach ($next[$roundId] ?? [] as $targetId) {
                if (($state[$targetId] ?? 0) === 1) {
                    return true;
                }

                if (($state[$targetId] ?? 0) === 0 && $visit($targetId)) {
                    return true;
                }
            }

            $state[$roundId] = 2;

            return false;
        };

        foreach (array_keys($next) as $roundId) {
            if (($state[(string) $roundId] ?? 0) === 0 && $visit((string) $roundId)) {
                return true;
            }
        }

        return false;
    }
}
