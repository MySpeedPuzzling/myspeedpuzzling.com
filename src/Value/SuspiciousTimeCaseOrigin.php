<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum SuspiciousTimeCaseOrigin: string
{
    // Raised by the scan
    case Detector = 'detector';
    // Flagged outside the app (SQL) - the scan's reconciliation opened the case
    case Manual = 'manual';
    // A moderator reported it from a page (later, not in v1)
    case Moderator = 'moderator';
}
