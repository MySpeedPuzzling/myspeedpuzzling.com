<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The evidence a participant's phone uploads (docs/features/online-competitions/README.md §5.6 "Evidence photos"):
 * photos (periodic, empty mat, start, candidate, button, end) and the ±5 s finish bursts.
 */
enum OnlineMediaKind: string
{
    case Periodic = 'periodic';
    case EmptyMat = 'empty_mat';
    case Start = 'start';
    case Candidate = 'candidate';
    case Button = 'button';
    case Burst = 'burst';
    case End = 'end';

    /**
     * A photo of the finished (or, at time-up, unfinished) puzzle - what the completeness check judges (README §5.8,
     * Phase 3): a finish candidate's, a Finished tap's, the end photo
     */
    public function isFinishPhoto(): bool
    {
        return $this === self::Candidate || $this === self::Button || $this === self::End;
    }
}
