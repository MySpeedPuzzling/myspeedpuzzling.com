<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use SpeedPuzzling\Web\Value\FirstTryAssessment;

/**
 * Somebody of the result already has another result of the puzzle marked as a first try
 * (docs/features/first-try-integrity.md).
 */
final class FirstTryAlreadyTaken extends \Exception
{
    public function __construct(
        readonly public FirstTryAssessment $assessment,
    ) {
        parent::__construct('A first try of this puzzle is already recorded for somebody of this result.');
    }
}
