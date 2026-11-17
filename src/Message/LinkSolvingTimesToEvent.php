<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;

/**
 * "Link my results" of the creation wizard's backfill path (docs/features/competitions-management/creation-wizard.md
 * "Backfill"): the actor's own solving times (solo, or a pair/team they are in) linked to one event (an edition
 * included) or to a series (a series pick - its edition is found by SeriesEditionMatch), all or nothing. Only the link
 * changes - nothing else of a time. The handler answers LinkedSolvingTimes.
 */
readonly final class LinkSolvingTimesToEvent implements SerializedByLock
{
    /**
     * @param list<string> $solvingTimeIds
     */
    public function __construct(
        public string $actorPlayerId,
        public null|string $targetCompetitionId,
        public null|string $targetSeriesId,
        public array $solvingTimeIds,
    ) {
        if (($targetCompetitionId === null) === ($targetSeriesId === null)) {
            throw new \InvalidArgumentException('Link to exactly one event or one series.');
        }
    }

    /**
     * One link at a time per player: a double submit waits for the first and finds its times linked (or waiting) - never
     * two waiting rows for one time
     */
    public function lockKey(): string
    {
        return 'link-times-' . strtolower($this->actorPlayerId);
    }
}
