<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

final class SolvingTimeResponse
{
    public function __construct(
        public string $time_id,
        public string $puzzle_id,
        public null|int $time_seconds,
        public null|string $finished_at,
        public bool $first_attempt,
        public bool $unboxed,
        public null|string $comment,
    ) {
    }
}
