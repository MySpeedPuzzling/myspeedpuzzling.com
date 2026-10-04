<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What approving a puzzle change request does with the puzzle's image.
 */
enum PuzzleChangeRequestImageChoice: string
{
    // Leave the puzzle's image as it is
    case Keep = 'keep';
    // Use the image the player proposed
    case Proposed = 'proposed';
    // The reviewer uploads a different image
    case Upload = 'upload';
}
