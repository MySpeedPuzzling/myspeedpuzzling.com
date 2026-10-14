<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\AccuracySummary;

/**
 * One group of the online accuracy report's breakdown (docs/features/online-competitions/README.md §5.9 "by device /
 * browser / camera placement", OnlineAccuracy): the participants whose phone was this device and browser - or whose
 * detector used this camera view -, how many of them finished (a tap or a detected finish), how many finishes the
 * detector caught, the raises it missed, the raises that were not the finish - premature (the reviewer marked the
 * photo as a gap: raised too early) apart from the false detections (stream RG) - and the distributions of the
 * detection error and the delta within the group.
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
        public int $prematureRaises,
        public int $falseDetections,
        public AccuracySummary $detectionError,
        public AccuracySummary $delta,
        // Finishes on a phone whose detector did not watch - apart from the missed raises (stream P2F)
        public int $detectorNotRunning = 0,
    ) {
    }
}
