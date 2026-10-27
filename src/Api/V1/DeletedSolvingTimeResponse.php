<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

final class DeletedSolvingTimeResponse
{
    public function __construct(
        public string $timeId,
        public string $deletedAt,
    ) {
    }
}
