<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class AdminOrganizationDetail
{
    /**
     * @param list<AdminCompetitionMaintainer> $maintainers
     * @param list<AdminSeries> $series
     * @param list<AdminCompetition> $events
     */
    public function __construct(
        public AdminOrganization $organization,
        public array $maintainers,
        public array $series,
        public array $events,
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
            ...$this->organization->toArray(),
            // The team besides its creator (addedByPlayerId) - both have the creator's rights on everything under it
            'maintainers' => array_map(static fn (AdminCompetitionMaintainer $maintainer): array => $maintainer->toArray(), $this->maintainers),
            // Its series (GET /internal-api/series/{seriesId} lists a series' editions) and one-time events
            'series' => array_map(static fn (AdminSeries $series): array => $series->toArray(), $this->series),
            'events' => array_map(static fn (AdminCompetition $event): array => $event->toArray(), $this->events),
        ];
    }
}
