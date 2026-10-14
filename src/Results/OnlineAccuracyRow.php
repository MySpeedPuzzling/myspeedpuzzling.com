<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\OnlineReviewAssessment;
use SpeedPuzzling\Web\Value\RoundEntryResult;

/**
 * One participant of the online accuracy report (docs/features/online-competitions/README.md §5.9): the tap the review
 * stands on - the one nearest to the confirmed moment, before a confirmation the suggested one -, the confirmed moment,
 * the reviewer's adjustment (confirmed − tap), where the confirmed moment came from, and how late the tap arrived.
 * Moments in epoch ms, elapsed times since the round's start.
 *
 * Phase 2 - the detection (OnlineReviewAssessment): the detected moment (the finish candidate the finish was detected
 * by - nearest to the confirmed moment within 10 s; before a confirmation the suggested one, else the one nearest to
 * the tap), the detection error (confirmed − detected) and the delta (tap − the candidate nearest to it), the missed
 * raises (taps without a candidate) and false raises (candidates neither the finish nor near a tap) - null while the
 * round's detection is off -, the camera view the phone's detector used and the phone (OnlineDeviceLabel).
 *
 * Phase 3 - the completeness check (README §5.8, OnlineAccuracy): its verdict on the confirmed photo (complete / gaps /
 * unsure / failed / pending) and how it compares with the reviewer's outcome (OnlineAccuracy::OUTCOME_*) - null while
 * the check is off, before a confirmation, or without a photo to compare.
 */
readonly final class OnlineAccuracyRow
{
    /**
     * @param OnlineReviewAssessment::STATUS_* $status
     */
    public function __construct(
        public string $participantId,
        public string $name,
        public string $status,
        public null|int $tapMomentMs,
        public null|int $tapElapsedMs,
        public null|int $confirmedMomentMs,
        public null|int $confirmedElapsedMs,
        public null|int $adjustmentMs,
        public null|string $source,
        public null|int $arrivedLateMs,
        public RoundEntryResult $official,
        public null|int $detectedMomentMs = null,
        public null|int $detectedElapsedMs = null,
        public null|int $detectionErrorMs = null,
        public null|int $deltaMs = null,
        public null|int $missedRaises = null,
        public null|int $falseRaises = null,
        // angled | topDown - null when the phone's detector never said
        public null|string $view = null,
        public null|string $device = null,
        public null|string $browser = null,
        // Finishes without a detection on a phone whose detector did not watch (stream P2F) - never a missed raise
        public null|int $detectorNotRunning = null,
        public null|string $completenessVerdict = null,
        public null|string $completenessOutcome = null,
    ) {
    }
}
