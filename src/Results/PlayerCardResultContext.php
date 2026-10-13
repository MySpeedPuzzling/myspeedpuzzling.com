<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * The player card's feed / notification context (docs/features/navigation CARD-02): the one result the card was opened
 * from (a row of the activity feed, a notification about a result) - its time, the standing of whoever solved it (the
 * player, or their pair/team) on the puzzle's leaderboard of that category and, for a solo result and a signed-in
 * viewer, the viewer's own best for the gap.
 */
readonly final class PlayerCardResultContext
{
    /**
     * @param list<string> $memberPlayerIds the registered members of a pair/team result
     */
    public function __construct(
        public string $timeId,
        public string $puzzleId,
        // 'solo' | 'duo' | 'team'
        public string $puzzlingType,
        // null = a relax result (no time)
        public null|int $time,
        // The subject's place on the puzzle's leaderboard of the category (their best result there), null without one
        public null|int $rank,
        public int $total,
        public null|int $subjectBestTime,
        public null|int $viewerTime,
        public bool $viewerSignedIn,
        public string $trackedByPlayerId,
        public array $memberPlayerIds,
    ) {
    }

    public function isSolo(): bool
    {
        return $this->puzzlingType === 'solo';
    }

    /** The result is the best of whoever solved it - the rank is this result's */
    public function isBest(): bool
    {
        return $this->time !== null && $this->time === $this->subjectBestTime;
    }

    /** Positive = the viewer is slower than this result, negative = faster */
    public function viewerGap(): null|int
    {
        if ($this->viewerTime === null || $this->time === null) {
            return null;
        }

        return $this->viewerTime - $this->time;
    }

    /** Whoever tracked the time and every registered pair/team member may edit it (PuzzleSolvingTime::canBeModifiedBy()) */
    public function isEditableBy(null|string $playerId): bool
    {
        if ($playerId === null) {
            return false;
        }

        return $playerId === $this->trackedByPlayerId || in_array($playerId, $this->memberPlayerIds, true);
    }
}
