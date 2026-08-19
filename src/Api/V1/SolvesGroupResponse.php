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
        public null|int $bestTimeSeconds,
        public null|int $lastTimeSeconds,
        public null|string $firstSolvedAt,
        public null|string $lastSolvedAt,
    ) {
    }

    public static function fromResult(PlayerPuzzleSolvesGroup $group): self
    {
        return new self(
            count: $group->count,
            bestTimeSeconds: $group->bestTimeSeconds,
            lastTimeSeconds: $group->lastTimeSeconds,
            firstSolvedAt: $group->firstSolvedAt?->format('c'),
            lastSolvedAt: $group->lastSolvedAt?->format('c'),
        );
    }
}
