<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

/**
 * The sell/swap section of the puzzle library summary. The list has no
 * visibility setting - it is public for everyone on the website; only a
 * private profile hides it (count 0).
 */
final class LibrarySellSwapSectionResponse
{
    public function __construct(
        public int $count,
    ) {
    }
}
