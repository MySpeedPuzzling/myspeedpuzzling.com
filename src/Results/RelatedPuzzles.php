<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class RelatedPuzzles
{
    /**
     * @param bool $samePiecesCount The puzzles share the current puzzle's brand and piece count; false = the whole brand
     * @param list<RelatedPuzzle> $puzzles The most-solved first, then the ones picked for this page
     */
    public function __construct(
        public bool $samePiecesCount,
        public array $puzzles,
    ) {
    }
}
