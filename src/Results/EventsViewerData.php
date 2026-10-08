<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\FollowTarget;
use SpeedPuzzling\Web\Value\FollowTargetKind;

/**
 * What the events page needs to know about the signed-in viewer (GetEventsViewerData): what they are going to, what
 * they follow, what they organise. Ids are lower case.
 */
readonly final class EventsViewerData
{
    /**
     * @param list<string> $goingCompetitionIds
     * @param list<string> $followedCompetitionIds
     * @param list<string> $followedSeriesIds
     * @param array<string, null|string> $organizedCompetitions competition id => its series id (null for a one-time event)
     * @param list<string> $organizedSeriesIds
     */
    public function __construct(
        public array $goingCompetitionIds = [],
        public array $followedCompetitionIds = [],
        public array $followedSeriesIds = [],
        public array $organizedCompetitions = [],
        public array $organizedSeriesIds = [],
    ) {
    }

    public function isGoing(string $competitionId): bool
    {
        return in_array(strtolower($competitionId), $this->goingCompetitionIds, true);
    }

    public function follows(FollowTarget $target): bool
    {
        if ($target->kind === FollowTargetKind::Series) {
            return in_array($target->id, $this->followedSeriesIds, true);
        }

        return in_array($target->id, $this->followedCompetitionIds, true);
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
     * The one rule shared by the "You organize (n)" button and the "You organize" page.
     */
    public function organizedCount(): int
    {
        return count($this->organizedCompetitionIds()) + count($this->organizedSeriesIds());
    }
}
