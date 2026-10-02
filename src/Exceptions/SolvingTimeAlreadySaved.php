<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * The result is already saved - the same form or API call sent again (docs/features/duplicate-results.md,
 * Layer 1). Nothing was created; the caller answers with the existing result.
 */
final class SolvingTimeAlreadySaved extends \Exception
{
    public function __construct(
        readonly public string $timeId,
        readonly public string $puzzleId,
    ) {
        parent::__construct('This result is already saved.');
    }
}
