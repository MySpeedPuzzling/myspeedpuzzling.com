<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * The id sent with a new puzzle belongs to a puzzle somebody else added. The id travels in the form, so it is
 * user input - the save is refused and the form gets a fresh id.
 */
final class PuzzleIdTaken extends \Exception
{
    public function __construct()
    {
        parent::__construct('The puzzle id is already used by a puzzle another player added.');
    }
}
