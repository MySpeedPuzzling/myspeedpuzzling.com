<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

/**
 * One solving time of a result list, with the solved puzzle's community
 * statistics (public, always an object) and difficulty (members-only: null
 * when the token owner is not a member or the token is a machine token - see
 * docs/features/api/README.md, Members-Exclusive Data).
 *
 * A duo/team result also names the pair/team it belongs to: `team_id` identifies the exact set of people
 * (same people = same id, in anybody's results), `team_name` is the name its members gave it, if any.
 * Both are null for a solo result. Additive fields - nothing else about the response changed.
 */
final class PlayerResultResponse
{
    public function __construct(
        public string $timeId,
        public string $puzzleId,
        public string $puzzleName,
        public string $manufacturerName,
        public int $piecesCount,
        public null|int $timeSeconds,
        public null|string $finishedAt,
        public bool $firstAttempt,
        public null|string $puzzleImage,
        public null|string $comment,
        public PuzzleStatisticsResponse $statistics,
        public null|PuzzleDifficultyResponse $difficulty = null,
        public null|string $teamId = null,
        public null|string $teamName = null,
    ) {
    }
}
