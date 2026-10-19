<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

use DateTimeImmutable;

/**
 * One pair/team result the player took part in (GetInsightsGroupResults) - not suspicious, timed.
 */
readonly final class InsightsGroupResult
{
    /**
     * @param list<GroupResultMember> $members every member, the player included, in the team's order
     */
    public function __construct(
        public string $timeId,
        public string $puzzleId,
        /** null = the puzzle is kept secret now */
        public null|InsightsPuzzleRef $puzzle,
        public int $piecesCount,
        public int $seconds,
        /** COALESCE(finished_at, tracked_at) */
        public DateTimeImmutable $solvedAt,
        public DateTimeImmutable $trackedAt,
        /** Everybody's first try (the flag is set only then) */
        public bool $firstAttempt,
        public bool $unboxed,
        public string $teamId,
        public null|string $teamName,
        public int $size,
        /** The puzzle's difficulty score when rated with more than insufficient confidence, else null */
        public null|float $difficulty,
        public array $members,
        /** solvedAt / trackedAt as epoch seconds the way the pace history keys them (PaceTimeline) */
        public float $solvedEpoch,
        public float $trackedEpoch,
    ) {
    }
}
