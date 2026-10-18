<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Only whoever may edit an event (CompetitionEditVoter: its organisers, its series' and organization's team, admins)
 * makes a copy of it.
 */
final class CompetitionNotEditable extends AccessDeniedHttpException
{
    public function __construct()
    {
        parent::__construct('You cannot edit this event.');
    }
}
