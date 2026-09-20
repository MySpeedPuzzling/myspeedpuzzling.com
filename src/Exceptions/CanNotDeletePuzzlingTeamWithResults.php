<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A pair/team that has results is part of those results - it can be named, never deleted.
 */
final class CanNotDeletePuzzlingTeamWithResults extends ConflictHttpException
{
}
