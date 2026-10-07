<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A waitlisted participant holds no spot: marking them paid takes an explicit promotion, and they are not checked in.
 */
final class ParticipantIsWaitlisted extends ConflictHttpException
{
}
