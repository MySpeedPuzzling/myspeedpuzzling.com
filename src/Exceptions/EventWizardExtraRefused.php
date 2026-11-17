<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A row of the creation wizard's Extras was refused (SaveEventWizardExtrasHandler) - `$row` is its form field, the
 * refusal itself the previous exception. Everything the step wrote is rolled back with it.
 */
final class EventWizardExtraRefused extends RuntimeException
{
    public function __construct(
        public readonly string $row,
        Throwable $refusal,
    ) {
        parent::__construct(sprintf('The Extras row "%s" was refused: %s', $row, $refusal->getMessage()), previous: $refusal);
    }
}
