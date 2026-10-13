<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The hands-up finish detection of an online round (docs/features/online-competitions/README.md §5.7):
 * - off: the Finished button is the finish (Phase 1)
 * - shadow: the phones detect and report candidates, the button stays the finish - to measure the detector
 * - on: the first complete candidate is the finish, the button is the fallback
 */
enum OnlineDetectionMode: string
{
    case Off = 'off';
    case Shadow = 'shadow';
    case On = 'on';
}
