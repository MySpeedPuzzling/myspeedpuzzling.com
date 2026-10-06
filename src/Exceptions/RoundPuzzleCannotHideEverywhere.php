<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Only the competition's own secret puzzle - unapproved, added by its organisers or created by this round - can be
 * kept hidden everywhere by a round (RoundPuzzleOwnership). A public catalogue puzzle or a placeholder never can.
 */
final class RoundPuzzleCannotHideEverywhere extends ConflictHttpException
{
}
