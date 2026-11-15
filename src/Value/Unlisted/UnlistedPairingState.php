<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\Unlisted;

/**
 * Where one "It is this catalogue puzzle" stands (docs/features/data-import/unlisted-puzzles.md "Pairing").
 */
enum UnlistedPairingState: string
{
    /** The worker is turning the unlisted puzzle's results and marks into real ones */
    case Running = 'running';
    case Finished = 'finished';
    /** The worker is taking back what the pairing made */
    case Undoing = 'undoing';
    case Undone = 'undone';
}
