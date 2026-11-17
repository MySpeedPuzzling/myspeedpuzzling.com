<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What an organiser declares for one advancement step (competition toolkit A8, A-round-formats.md §3.5): exactly the
 * settings AdvanceQualified takes (sources, targets, distribution, the by-source map, the country rule) plus how many
 * qualify. A preset only - qualifying stays the organiser's decision and advancing stays AdvanceQualified's dry run +
 * plan hash; nothing here ever marks or moves anybody by itself.
 */
readonly final class AdvancementStepSettings
{
    public const int QUALIFY_MAX = 9999;
    public const int COUNTRY_MAX = 99;

    /**
     * @param list<string> $sourceRoundIds in the organiser's order
     * @param list<string> $targetRoundIds in the organiser's order (the serpentine of "balanced" follows it)
     * @param array<string, string> $targetBySource source round id => target round id, `by_source` only
     * @param null|int $qualifyCount how many qualify, null = the organiser marks by hand
     * @param null|int $bestOfEachCountry the country rule, null = off
     */
    public function __construct(
        public array $sourceRoundIds,
        public array $targetRoundIds,
        public AdvanceDistribution $distribution,
        public array $targetBySource = [],
        public null|int $qualifyCount = null,
        public AdvancementQualifyScope $qualifyScope = AdvancementQualifyScope::PerRound,
        public null|int $bestOfEachCountry = null,
    ) {
    }

    /**
     * Lower-cased ids, each round once in its first place, the map only for this step's sources onto its targets (and
     * only for `by_source`).
     */
    public function normalised(): self
    {
        $sources = array_values(array_unique(array_map(strtolower(...), $this->sourceRoundIds)));
        $targets = array_values(array_unique(array_map(strtolower(...), $this->targetRoundIds)));
        $map = [];

        if ($this->distribution === AdvanceDistribution::BySource) {
            foreach ($this->targetBySource as $source => $target) {
                $source = strtolower((string) $source);
                $target = strtolower($target);

                if (in_array($source, $sources, true) && in_array($target, $targets, true)) {
                    $map[$source] = $target;
                }
            }
        }

        return new self($sources, $targets, $this->distribution, $map, $this->qualifyCount, $this->qualifyScope, $this->bestOfEachCountry);
    }

    /**
     * @return list<string> every round of the step, sources first
     */
    public function roundIds(): array
    {
        return array_values(array_unique([...$this->sourceRoundIds, ...$this->targetRoundIds]));
    }
}
