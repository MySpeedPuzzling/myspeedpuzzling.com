<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * An entry of an earlier round with its advancement seed (AdvancementSeeding): 1 = the best.
 */
readonly final class SeededEntry
{
    public function __construct(
        public int $seed,
        public string $sourceRoundId,
        public RoundResultEntry $entry,
    ) {
    }
}
