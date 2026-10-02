<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * Somebody else proposed the merge or dismissed the signal in the meantime. Nothing was changed.
 */
final class DuplicatePuzzleSignalAlreadyResolved extends \Exception
{
}
