<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a distinct puzzle of an import is decided to be (docs/features/data-import/pairing.md "Decisions").
 */
enum ImportPuzzleChoice: string
{
    case Undecided = 'undecided';
    /** A puzzle of the catalogue - `puzzle_id` */
    case Catalogue = 'catalogue';
    /** Kept as the player's own unlisted puzzle (the entity arrives with stage 7 - here only the decision) */
    case Unlisted = 'unlisted';
    /** Its rows are not added */
    case LeftOut = 'left_out';
}
