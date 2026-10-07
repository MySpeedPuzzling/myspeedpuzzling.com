<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum SuspiciousTimeCaseStatus: string
{
    // Raised by the scan, waiting for a moderator
    case Pending = 'pending';
    // The time is flagged (puzzle_solving_time.suspicious) - by a moderator or by SQL
    case Marked = 'marked';
    // A person said the time is fine (also an unmark) - this entry is never raised again
    case Trusted = 'trusted';
    // The player fixed the time and the corrected entry passed - unmarked automatically
    case Corrected = 'corrected';
    // No longer raised (edited, moved, a piece count fixed) before anybody decided
    case Gone = 'gone';

    /**
     * A person decided about the entry - the scan never checks it again while its fingerprint is unchanged.
     *
     * @return list<self>
     */
    public static function decided(): array
    {
        return [self::Marked, self::Trusted];
    }
}
