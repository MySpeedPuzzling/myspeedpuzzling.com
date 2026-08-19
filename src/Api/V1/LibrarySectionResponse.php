<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

/**
 * One section of the puzzle library summary (unsolved puzzles, wishlist,
 * solved puzzles): how many puzzles it holds and the owner's visibility
 * setting for it ("public" or "private"). For a section the token may not
 * see, the count is 0 and the visibility says why ("private").
 */
final class LibrarySectionResponse
{
    public function __construct(
        public int $count,
        public string $visibility,
    ) {
    }
}
