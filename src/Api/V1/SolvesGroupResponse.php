<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use SpeedPuzzling\Web\Results\PlayerPuzzleSolvesGroup;

/**
 * The token owner's solves of one puzzle in one discipline.
 */
final class SolvesGroupResponse
{
    public function __construct(
        public int $count,
        public null|int $best_time_seconds,
        public null|int $last_time_seconds,
        public null|string $first_solved_at,
        public null|string $last_solved_at,
    ) {
    }

    public static function fromResult(PlayerPuzzleSolvesGroup $group): self
    {
        return new self(
            count: $group->count,
            best_time_seconds: $group->bestTimeSeconds,
            last_time_seconds: $group->lastTimeSeconds,
            first_solved_at: $group->firstSolvedAt?->format('c'),
            last_solved_at: $group->lastSolvedAt?->format('c'),
        );
    }
}
