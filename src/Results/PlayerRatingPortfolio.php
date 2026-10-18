<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * A player's MSP rating at one piece count with every entry behind it. The entries are scored for every player with
 * solves on rated puzzles - also before they qualify for the ladder (rating 0.0), so "how far am I from the ladder"
 * can be answered from them.
 */
readonly final class PlayerRatingPortfolio
{
    /**
     * @param list<RatingEntry> $entries first-attempt entries first, each portfolio best first
     */
    public function __construct(
        public float $rating,
        public array $entries,
    ) {
    }
}
