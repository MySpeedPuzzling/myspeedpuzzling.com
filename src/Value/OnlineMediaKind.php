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
}
