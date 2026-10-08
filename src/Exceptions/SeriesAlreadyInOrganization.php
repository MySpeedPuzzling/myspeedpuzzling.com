<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * CreateOrganizationFromSeries on a series that belongs to an organization already - move it out first
 * (AssignEventToOrganization), one organization per item.
 */
final class SeriesAlreadyInOrganization extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('The series belongs to an organization already. Nothing was changed.');
    }
}
