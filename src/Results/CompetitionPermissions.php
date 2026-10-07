<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What one player may manage among competitions and series - the answers the
 * CompetitionEdit/Delete, CompetitionResultsEntry and CompetitionSeriesEdit/Delete voters give.
 * Ids are stored lower-cased (Postgres' uuid text form).
 */
readonly final class CompetitionPermissions
{
    /**
     * @param array<string, true> $editableCompetitionIds
     * @param array<string, true> $deletableCompetitionIds
     * @param array<string, true> $editableSeriesIds
     * @param array<string, true> $deletableSeriesIds
     * @param array<string, true> $refereeCompetitionIds
     */
    public function __construct(
        private array $editableCompetitionIds,
        private array $deletableCompetitionIds,
        private array $editableSeriesIds,
        private array $deletableSeriesIds,
        private array $refereeCompetitionIds = [],
    ) {
    }

    /**
     * Owner or maintainer of the competition, or owner or maintainer of its series.
     */
    public function canEditCompetition(string $competitionId): bool
    {
        return isset($this->editableCompetitionIds[strtolower($competitionId)]);
    }

    /**
     * Organiser (see canEditCompetition()) or referee of the competition - the live result entry
     * (docs/features/competitions-management/live-results.md "Referees").
     */
    public function canEnterResults(string $competitionId): bool
    {
        return $this->canEditCompetition($competitionId)
            || isset($this->refereeCompetitionIds[strtolower($competitionId)]);
    }

    /**
     * Owner of the competition, or owner of its series.
     */
    public function canDeleteCompetition(string $competitionId): bool
    {
        return isset($this->deletableCompetitionIds[strtolower($competitionId)]);
    }

    /**
     * Owner or maintainer of the series.
     */
    public function canEditSeries(string $seriesId): bool
    {
        return isset($this->editableSeriesIds[strtolower($seriesId)]);
    }

    /**
     * Owner of the series.
     */
    public function canDeleteSeries(string $seriesId): bool
    {
        return isset($this->deletableSeriesIds[strtolower($seriesId)]);
    }
}
