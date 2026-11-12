<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use RuntimeException;

/**
 * An organizer's results sheet could not be read: not shared "Anyone with the link", Google unreachable or answering
 * something we do not understand (docs/features/event-results-import/README.md "What we read").
 */
final class GoogleSheetUnreadable extends RuntimeException
{
}
