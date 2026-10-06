<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * A typed local date and time that does not exist exactly once in its zone (RoundTimezone::parseLocal()).
 */
final class InvalidLocalTime extends \Exception
{
}
