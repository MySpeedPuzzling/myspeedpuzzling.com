<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\StandingMode;
use SpeedPuzzling\Web\Value\StandingRules;

/**
 * A standing as the organiser's pages list it (GetCompetitionStandings).
 */
readonly final class CompetitionStandingOverview
{
    /**
     * @param list<string> $roundIds as stored - readers skip ids that are no round of the event and category any more
     */
    public function __construct(
        public string $id,
        public string $competitionId,
        public string $name,
        public string $slug,
        public RoundCategory $category,
        public StandingMode $mode,
        public array $roundIds,
        public StandingRules $rules,
        public int $position,
        public null|DateTimeImmutable $publishedAt,
        // `stages` only (competition toolkit v2 S5): one number over everybody across the stages
        public bool $numberedAcrossStages = false,
        // Real events L2a (shared-puzzles-and-divisions.md D7): limited to one division - null = everybody
        public null|RoundDivision $division = null,
    ) {
    }

    public function isStages(): bool
    {
        return $this->mode === StandingMode::Stages;
    }

    /**
     * `numberedAcrossStages` only for a `stages` standing, `division` only for a standing limited to one (real events
     * L2a) - every other standing's JSON stays as it was.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'category' => $this->category->value,
            'mode' => $this->mode->value,
            'published' => $this->publishedAt !== null,
            'rules' => $this->rules->toArray(),
        ];

        if ($this->isStages()) {
            $array['numberedAcrossStages'] = $this->numberedAcrossStages;
        }

        if ($this->division !== null) {
            $array['division'] = $this->division->jsonSerialize();
        }

        return $array;
    }
}
