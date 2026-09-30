<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * The conflict the player answered is not there any more in that shape - resolved elsewhere, or a result
 * changed meanwhile. Nothing was changed.
 */
final class FirstTryConflictChanged extends \Exception
{
}
