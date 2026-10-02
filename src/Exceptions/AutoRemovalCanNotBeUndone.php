<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * The removed result cannot come back: it was brought back already, or its puzzle no longer exists.
 */
final class AutoRemovalCanNotBeUndone extends \Exception
{
}
