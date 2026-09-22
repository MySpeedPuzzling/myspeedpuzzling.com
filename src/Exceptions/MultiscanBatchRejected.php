<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * A multiscan batch is all-or-nothing: the first puzzle that is not eligible
 * for the action stops the whole batch before anything is written.
 */
final class MultiscanBatchRejected extends \Exception
{
    public function __construct(
        public readonly null|string $puzzleId,
        public readonly string $reason,
        null|\Throwable $previous = null,
    ) {
        parent::__construct(sprintf('Multiscan batch rejected: %s (%s)', $reason, $puzzleId ?? '-'), 0, $previous);
    }
}
