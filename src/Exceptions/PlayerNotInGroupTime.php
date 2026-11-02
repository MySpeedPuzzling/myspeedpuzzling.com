<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Only a registered member of a pair/team result other than its tracker can be replaced - the result is the tracker's.
 */
final class PlayerNotInGroupTime extends ConflictHttpException
{
    public static function notInGroup(): self
    {
        return new self('The player is not a registered member of this pair/team result.');
    }

    public static function isTracker(): self
    {
        return new self('The player tracked this result - it is theirs, they cannot be replaced in it.');
    }
}
