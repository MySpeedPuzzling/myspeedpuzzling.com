<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\OnlineReviewAssessment;
use SpeedPuzzling\Web\Value\RoundEntryResult;

/**
 * One participant of the online accuracy report (docs/features/online-competitions/README.md §5.9, Phase 1): the tap
 * the review stands on - the one nearest to the confirmed moment, before a confirmation the suggested one -, the
 * confirmed moment, the reviewer's adjustment (confirmed − tap), where the confirmed moment came from, and how late the
 * tap arrived. Moments in epoch ms, elapsed times since the round's start.
 *
 * Phase 2 adds the detection here: the detected moment (the finish candidate nearest to the confirmed moment), the
 * detection error (confirmed − detected) and the delta (tap − detected) - columns next to the adjustment, measured like
 * it (OnlineAccuracy::MEASURES).
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
    ) {
    }
}
