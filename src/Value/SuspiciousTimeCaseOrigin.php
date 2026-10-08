<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum SuspiciousTimeCaseOrigin: string
{
    // Raised by the scan
    case Detector = 'detector';
    // Flagged outside the app (SQL) - the scan's reconciliation opened the case
    case Manual = 'manual';
    // A person flagged it without a scan case - the internal API's mark-suspicious (a page for it later)
    case Moderator = 'moderator';
}
