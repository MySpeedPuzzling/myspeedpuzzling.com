<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

final class DatabaseNotReady extends \RuntimeException
{
    public function __construct(
        public readonly int $attempts,
        \Throwable $lastError,
    ) {
        parent::__construct(
            sprintf('Database did not answer after %d attempts: %s', $attempts, $lastError->getMessage()),
            previous: $lastError,
        );
    }
}
