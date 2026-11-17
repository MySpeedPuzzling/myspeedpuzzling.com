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
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'category' => $this->category->value,
            'mode' => $this->mode->value,
            'published' => $this->publishedAt !== null,
            'rules' => $this->rules->toArray(),
        ];
    }
}
