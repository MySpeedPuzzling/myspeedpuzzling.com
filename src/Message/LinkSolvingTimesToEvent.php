<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * "Link my results" of the creation wizard's backfill path (docs/features/competitions-management/creation-wizard.md
 * "Backfill"): the actor's own solving times (solo, or a pair/team they are in) linked to one event (an edition
 * included) or to a series (a series pick - its edition is found by SeriesEditionMatch), all or nothing. Only the link
 * changes - nothing else of a time. The handler answers LinkedSolvingTimes.
 */
readonly final class LinkSolvingTimesToEvent
{
    /**
     * @param list<string> $solvingTimeIds
     */
    public function __construct(
        public string $actorPlayerId,
        public null|string $competitionId,
        public null|string $seriesId,
        public array $solvingTimeIds,
    ) {
        if (($competitionId === null) === ($seriesId === null)) {
            throw new \InvalidArgumentException('Link to exactly one event or one series.');
        }
    }
}
