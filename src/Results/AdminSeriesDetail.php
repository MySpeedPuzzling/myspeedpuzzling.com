<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class AdminSeriesDetail
{
    /**
     * @param list<AdminCompetitionMaintainer> $maintainers
     * @param list<AdminCompetition> $editions
     */
    public function __construct(
        public AdminSeries $series,
        public array $maintainers,
        public array $editions,
    ) {
    }

    /**
     * @return list<string>
     */
    public function maintainerIds(): array
    {
        return array_map(static fn (AdminCompetitionMaintainer $maintainer): string => $maintainer->playerId, $this->maintainers);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...$this->series->toArray(),
            'maintainers' => array_map(static fn (AdminCompetitionMaintainer $maintainer): array => $maintainer->toArray(), $this->maintainers),
            // Every edition with the competition list's fields (ids, slugs, dates, state, round / result / participant
            // counts) - read one with GET /internal-api/competitions/{competitionId} for its rounds
            'editions' => array_map(static fn (AdminCompetition $edition): array => $edition->toArray(), $this->editions),
        ];
    }
}
