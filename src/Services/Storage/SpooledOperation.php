<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Storage;

final readonly class SpooledOperation
{
    public function __construct(
        public string $key,
        public SpooledOperationType $op,
        public \DateTimeImmutable $firstFailedAt,
        public \DateTimeImmutable $lastAttemptAt,
        public int $attempts,
        public string $lastError,
    ) {
    }
}
