<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The tabs of the moderator queue /admin/time-verification (docs/features/suspicious-time-review.md, "Moderator
 * queue"). Labels are what moderators read - never "suspicious".
 */
enum SuspiciousTimeQueueTab: string
{
    // Pending cases, much faster than expected
    case Fast = 'fast';
    // Pending cases, much slower than expected
    case Slow = 'slow';
    // Marked cases the player answered "The time is correct" for, or edited and still needing a person
    case Replied = 'replied';
    // Marked cases
    case Marked = 'marked';
    // Trusted cases
    case Trusted = 'trusted';
    case Log = 'log';
    case Numbers = 'numbers';

    public function label(): string
    {
        return match ($this) {
            self::Fast => 'Too fast',
            self::Slow => 'Too slow',
            self::Replied => 'Player replied',
            self::Marked => 'Needs verification',
            self::Trusted => 'Verified fine',
            self::Log => 'Decision log',
            self::Numbers => 'Numbers',
        };
    }

    public function direction(): null|SuspicionDirection
    {
        return match ($this) {
            self::Fast => SuspicionDirection::Fast,
            self::Slow => SuspicionDirection::Slow,
            default => null,
        };
    }

    /**
     * A tab listing case cards (the other two show tables).
     */
    public function listsCases(): bool
    {
        return $this !== self::Log && $this !== self::Numbers;
    }
}
