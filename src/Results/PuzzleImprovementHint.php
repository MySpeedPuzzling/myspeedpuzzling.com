<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What a signed-in player could improve on a puzzle's page (docs/features/puzzles-needing-improvement.md).
 */
readonly final class PuzzleImprovementHint
{
    public function __construct(
        public bool $eanMissing,
        public bool $imageNeedsImprovement,
        /** Only ever true for admins and community moderators */
        public bool $wrongPicture,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->eanMissing === false && $this->imageNeedsImprovement === false && $this->wrongPicture === false;
    }
}
