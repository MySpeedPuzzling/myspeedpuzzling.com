<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What the player did about a marked time.
 */
enum SuspiciousTimeResponse: string
{
    case Fixed = 'fixed';
    case SaysCorrect = 'says_correct';
    case LeftAsIs = 'left_as_is';
}
