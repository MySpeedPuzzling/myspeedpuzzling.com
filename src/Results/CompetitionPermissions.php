<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What one player may manage among competitions, series and organizations - the answers the
 * CompetitionEdit/Delete, CompetitionResultsEntry, CompetitionSeriesEdit/Delete and OrganizationEdit/Delete voters give.
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
     * @param array<string, true> $editableOrganizationIds
     * @param array<string, true> $deletableOrganizationIds
     */
    public function __construct(
        private array $editableCompetitionIds,
        private array $deletableCompetitionIds,
        private array $editableSeriesIds,
        private array $deletableSeriesIds,
        private array $refereeCompetitionIds = [],
        private array $editableOrganizationIds = [],
        private array $deletableOrganizationIds = [],
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

    /**
     * Creator or maintainer of the organization - its team.
     */
    public function canEditOrganization(string $organizationId): bool
    {
        return isset($this->editableOrganizationIds[strtolower($organizationId)]);
    }

    /**
     * Creator of the organization.
     */
    public function canDeleteOrganization(string $organizationId): bool
    {
        return isset($this->deletableOrganizationIds[strtolower($organizationId)]);
    }

    /**
     * The organizations the player is on the team of - the choices of the "Organization" select.
     *
     * @return list<string>
     */
    public function organizationIds(): array
    {
        return array_map(strval(...), array_keys($this->editableOrganizationIds));
    }
}
