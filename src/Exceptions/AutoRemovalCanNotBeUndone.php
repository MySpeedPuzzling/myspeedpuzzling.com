<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * The removed result cannot come back: it was brought back already, its puzzle no longer exists, or a member of its
 * pair/team deleted their account meanwhile.
 */
final class AutoRemovalCanNotBeUndone extends \Exception
{
}
