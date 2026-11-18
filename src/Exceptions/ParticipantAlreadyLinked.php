<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A personal claim link is made for people not linked to an account (docs/features/competitions-management/
 * participant-linking.md §5.2, §7.1) - this participant is linked already. Nothing was changed.
 */
final class ParticipantAlreadyLinked extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('The participant is linked to a MySpeedPuzzling profile already.');
    }
}
