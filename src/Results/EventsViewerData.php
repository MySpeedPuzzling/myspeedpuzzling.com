<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\FollowTarget;
use SpeedPuzzling\Web\Value\FollowTargetKind;

/**
 * What the events page needs to know about the signed-in viewer (GetEventsViewerData): what they are going to, what
 * they follow, what they organise - organizations included (docs/features/organizations/README.md). Ids are lower case.
 */
readonly final class EventsViewerData
{
    /**
     * @param list<string> $goingCompetitionIds
     * @param list<string> $followedCompetitionIds
     * @param list<string> $followedSeriesIds
     * @param array<string, null|string> $organizedCompetitions competition id => its series id (null for a one-time event)
     * @param list<string> $organizedSeriesIds
     * @param list<string> $followedOrganizationIds
     * @param list<string> $organizedOrganizationIds the organizations the viewer is on the team of
     * @param array<string, string> $organizationOfItem organized competition or series id => the organization it is under
     */
    public function __construct(
        public array $goingCompetitionIds = [],
        public array $followedCompetitionIds = [],
        public array $followedSeriesIds = [],
        public array $organizedCompetitions = [],
        public array $organizedSeriesIds = [],
        public array $followedOrganizationIds = [],
        public array $organizedOrganizationIds = [],
        public array $organizationOfItem = [],
    ) {
    }

    public function isGoing(string $competitionId): bool
    {
        return in_array(strtolower($competitionId), $this->goingCompetitionIds, true);
    }

    public function follows(FollowTarget $target): bool
    {
        return match ($target->kind) {
            FollowTargetKind::Series => in_array($target->id, $this->followedSeriesIds, true),
            FollowTargetKind::Organization => in_array($target->id, $this->followedOrganizationIds, true),
            FollowTargetKind::Competition => in_array($target->id, $this->followedCompetitionIds, true),
        };
    }

    public function followsOrganization(string $organizationId): bool
    {
        return in_array(strtolower($organizationId), $this->followedOrganizationIds, true);
    }

    /**
     * @return list<string>
     */
    public function followedOrganizationIds(): array
    {
        return $this->followedOrganizationIds;
    }

    /**
     * @return list<string>
     */
    public function organizedOrganizationIds(): array
    {
        return $this->organizedOrganizationIds;
    }

    /**
     * The organization an organized competition or series is under - null when none
     */
    public function organizationOf(string $itemId): null|string
    {
        return $this->organizationOfItem[strtolower($itemId)] ?? null;
    }

    /**
     * @return list<string>
     */
    public function followedSeriesIds(): array
    {
        return $this->followedSeriesIds;
    }

    /**
     * @return list<string>
     */
    public function followedCompetitionIds(): array
    {
        return $this->followedCompetitionIds;
    }

    /**
     * One-time events, plus editions whose series the viewer does not organise (those are under their series).
     *
     * @return list<string>
     */
    public function organizedCompetitionIds(): array
    {
        $ids = [];

        foreach ($this->organizedCompetitions as $competitionId => $seriesId) {
            if ($seriesId === null || in_array($seriesId, $this->organizedSeriesIds, true) === false) {
                $ids[] = (string) $competitionId;
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    public function organizedSeriesIds(): array
    {
        return $this->organizedSeriesIds;
    }

    /**
     * The one rule shared by the "You organize (n)" button and the "You organize" page (docs/features/organizations/
     * README.md, P9): the viewer's organizations, plus the series and events not under one of them (those are listed
     * under their organization).
     */
    public function organizedCount(): int
    {
        $notUnderOwnOrganization = fn (string $itemId): bool => $this->organizationOf($itemId) === null
            || in_array($this->organizationOf($itemId), $this->organizedOrganizationIds, true) === false;

        return count($this->organizedOrganizationIds)
            + count(array_filter($this->organizedCompetitionIds(), $notUnderOwnOrganization))
            + count(array_filter($this->organizedSeriesIds(), $notUnderOwnOrganization));
    }
}
