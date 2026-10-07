<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * A moderator's answer to "The time is correct".
 */
enum SuspiciousTimeReplyAnswer: string
{
    // "Your time counts again"
    case Trusted = 'trusted';
    // "Stays marked" + a note
    case Kept = 'kept';
}
