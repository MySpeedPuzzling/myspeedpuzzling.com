<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use SpeedPuzzling\Web\Entity\CompetitionDisplay;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * The event has CompetitionDisplay::MAX_PER_COMPETITION displays already.
 */
final class CompetitionDisplayLimitReached extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct(sprintf('An event can have at most %d displays', CompetitionDisplay::MAX_PER_COMPETITION));
    }
}
