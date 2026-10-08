<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Only an organization waiting for approval (neither approved nor rejected) can be approved.
 */
final class OrganizationNotApprovable extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('The organization is not waiting for approval.');
    }
}
