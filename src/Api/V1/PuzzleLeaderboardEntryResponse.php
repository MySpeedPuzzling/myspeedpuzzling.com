<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

/**
 * One row of GET /api/v1/puzzles/{puzzleId}/leaderboard: the best time of one
 * player (solo) or of one exact pair / team (duo, team).
 */
final readonly class PuzzleLeaderboardEntryResponse
{
    /**
     * @param list<PuzzleLeaderboardPlayerResponse> $players
     */
    public function __construct(
        /** Position in the filtered leaderboard, 1-based */
        public int $rank,
        public string $timeId,
        public int $timeSeconds,
        public null|string $finishedAt,
        public bool $firstAttempt,
        /** The pair / team (puzzling_team) of a duo or team row, null for solo */
        public null|string $teamId,
        /** Its shared name; for an imported event result nobody has claimed, the name from the results sheet */
        public null|string $teamName,
        /** One for solo, every member in order for a pair / team */
        public array $players,
    ) {
    }
}
