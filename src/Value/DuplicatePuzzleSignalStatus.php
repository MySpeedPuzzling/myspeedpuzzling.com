<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum DuplicatePuzzleSignalStatus: string
{
    // Waiting for an admin
    case Open = 'open';
    // A merge request went to the merge review
    case MergeProposed = 'merge_proposed';
    // Two different puzzles after all
    case Dismissed = 'dismissed';
}
