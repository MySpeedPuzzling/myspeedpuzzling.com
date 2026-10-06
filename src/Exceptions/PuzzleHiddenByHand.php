<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A puzzle hidden by hand (an approved placeholder like Ravensburger Puzzle Month - hide dates set outside any
 * competition) is no round's to hide, reveal or show: adding it to a round, or changing a round's reveal of it, is
 * refused.
 */
final class PuzzleHiddenByHand extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('The puzzle is hidden by hand (a placeholder, not a competition\'s secret) - no round can hide, reveal or show it. Nothing was changed.');
    }
}
