<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

final class PlayerResultResponse
{
    public function __construct(
        public string $time_id,
        public string $puzzle_id,
        public string $puzzle_name,
        public string $manufacturer_name,
        public int $pieces_count,
        public null|int $time_seconds,
        public null|string $finished_at,
        public bool $first_attempt,
        public null|string $puzzle_image,
        public null|string $comment,
    ) {
    }
}
