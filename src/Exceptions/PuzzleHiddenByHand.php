<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A puzzle hidden by hand (an approved placeholder like Ravensburger Puzzle Month - hide dates set outside any
 * competition) is no round's to hide or reveal: adding it to a round, or changing a round's reveal of it, is refused.
 */
final class PuzzleHiddenByHand extends ConflictHttpException
{
}
