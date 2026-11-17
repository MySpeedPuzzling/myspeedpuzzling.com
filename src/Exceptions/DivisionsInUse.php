<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Saving the event's divisions would take their division from `count` round entries (a division removed, or removed
 * from a round) - nothing changed; the same save with `confirmClearing` clears them (shared-puzzles-and-divisions.md
 * §3.3, D1).
 */
final class DivisionsInUse extends ConflictHttpException implements InternalApiErrorCode
{
    public function __construct(
        public readonly int $count,
    ) {
        parent::__construct(sprintf('%d round entries would lose their division - send it again with "confirmClearing": true to clear them.', $count));
    }

    public function internalApiErrorCode(): string
    {
        return 'divisions_in_use';
    }
}
