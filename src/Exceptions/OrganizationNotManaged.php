<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Only the organization's team (its creator and maintainers) and admins put a series or a one-time event under it.
 */
final class OrganizationNotManaged extends AccessDeniedHttpException
{
    public function __construct()
    {
        parent::__construct("You are not on the organization's team.");
    }
}
