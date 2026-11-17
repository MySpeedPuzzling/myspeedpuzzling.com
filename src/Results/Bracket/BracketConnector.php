<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Bracket;

/**
 * A line of the bracket between two neighbouring stages (competition toolkit A9): from the middle of a source round's
 * box to the middle of a target round's box. The y values are in the connector's own viewBox (0-1000 top to bottom):
 * every box sits in the middle of an equal slot of its stage column, so its middle is (index + ½) / rounds × 1000.
 */
readonly final class BracketConnector
{
    public const int HEIGHT = 1000;

    public function __construct(
        public string $fromRoundId,
        public string $toRoundId,
        public int $fromY,
        public int $toY,
    ) {
    }

    /**
     * An SVG path from the left edge (x 0) to the right edge (x 100): a soft S-curve.
     */
    public function path(): string
    {
        return sprintf('M0 %d C50 %d 50 %d 100 %d', $this->fromY, $this->fromY, $this->toY, $this->toY);
    }
}
