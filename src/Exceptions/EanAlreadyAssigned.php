<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * The code already belongs to another puzzle (possibly a hidden one - the
 * message shown to the user stays generic on purpose).
 */
final class EanAlreadyAssigned extends \Exception
{
}
