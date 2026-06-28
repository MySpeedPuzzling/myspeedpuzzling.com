<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

final class CompetitionRoundResponse
{
    /** @var array<CompetitionRoundPuzzleResponse> */
    public array $puzzles;

    /**
     * @param array<CompetitionRoundPuzzleResponse> $puzzles
     */
    public function __construct(
        public string $id,
        public string $name,
        public null|string $starts_at,
        public int $minutes_limit,
        public string $category,
        array $puzzles,
    ) {
        $this->puzzles = $puzzles;
    }
}
