<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

readonly final class DeletedSolvingTime
{
    public function __construct(
        public string $timeId,
        public DateTimeImmutable $deletedAt,
    ) {
    }
}
