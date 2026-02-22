<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

final class StatisticsGroupResponse
{
    public function __construct(
        public int $total_seconds,
        public int $total_pieces,
        public int $solved_puzzles_count,
    ) {
    }
}
