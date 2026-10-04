<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What saving a puzzle's record does with its image - a change request approval or a direct edit.
 * Stored in the decision log's details ("image") - never rename a value.
 */
enum PuzzleImageChoice: string
{
    // Leave the puzzle's image as it is
    case Keep = 'keep';
    // Use the image the player proposed (change requests only)
    case Proposed = 'proposed';
    // The moderator uploads a different image
    case Upload = 'upload';
}
