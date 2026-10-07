<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * A moderator's decision in the time verification queue was made on what the page showed, and that changed in the
 * meantime: another moderator decided, the scan closed or refreshed the case, the player edited the time, or the
 * puzzle's piece count changed. Nothing was changed.
 */
final class SuspiciousTimeCaseChanged extends \Exception
{
}
