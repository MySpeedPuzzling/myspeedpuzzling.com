<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * An edition never has an organization of its own - it is its series' (docs/features/organizations/README.md).
 */
final class OrganizationOnEdition extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct("An edition belongs to its series' organization.");
    }
}
