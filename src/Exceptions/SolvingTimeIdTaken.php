<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * The id sent with a new result belongs to another player's result. The id travels in the form, so it is
 * user input - the save is refused and the form gets a fresh id.
 */
final class SolvingTimeIdTaken extends \Exception
{
    public function __construct()
    {
        parent::__construct('The result id is already used by another player.');
    }
}
