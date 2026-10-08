<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Guests typed as several people into one co-puzzler input ("Anna, Ben, Clara") become those people:
 * the results move to the pair/team they really are. Before the picker split commas, such a result was
 * saved as a pair with one oddly named guest.
 */
readonly final class SplitCombinedGuests
{
    public function __construct(
        public bool $dryRun,
    ) {
    }
}
