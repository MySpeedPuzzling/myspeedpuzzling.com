<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * TimeProposalTiming::judge()'s answer: the verdict, the heartbeat receipt it rests on (the clean heartbeat that makes
 * a proposal suspicious, else the last one heard - null when none), and whether the phone's clock is in doubt.
 */
readonly final class TimeProposalTimingVerdict
{
    public function __construct(
        public TimeProposalVerdict $verdict,
        public null|int $lastHeardAtMs,
        public bool $clockUncertain,
    ) {
    }
}
