<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * No room for another field: too many in use (archive one), or - $withArchived - too many in all, the archived ones
 * counted (restore an archived one instead of making a new one).
 */
final class CustomFieldLimitReached extends \Exception
{
    public function __construct(
        public readonly bool $withArchived = false,
    ) {
        parent::__construct($withArchived ? 'Too many custom fields, the archived ones counted.' : 'Too many custom fields in use.');
    }
}
