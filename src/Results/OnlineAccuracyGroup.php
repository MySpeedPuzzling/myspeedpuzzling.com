<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\AccuracySummary;

/**
 * One group of the online accuracy report's breakdown (docs/features/online-competitions/README.md §5.9 "by device /
 * browser / camera placement", OnlineAccuracy): the participants whose phone was this device and browser - or whose
 * detector used this camera view -, how many of them finished (a tap or a detected finish), how many finishes the
 * detector caught, the raises it missed and the false ones, and the distributions of the detection error and the delta
 * within the group.
 */
readonly final class OnlineAccuracyGroup
{
    public function __construct(
        // "iPhone · Safari 18", "angled" - null: not known (no device.ready, no detector)
        public null|string $label,
        public int $participants,
        public int $finishes,
        public int $detected,
        public int $missedRaises,
        public int $falseRaises,
        public AccuracySummary $detectionError,
        public AccuracySummary $delta,
    ) {
    }
}
