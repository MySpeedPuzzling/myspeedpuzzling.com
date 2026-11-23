<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * A field a pending change request changes, with the value it proposes for it as stored (the codes as the stored
 * list - shown through puzzle_eans() / puzzle_brand_codes(), the brand by its name, the picture as its file).
 */
readonly final class PendingFieldProposal
{
    public function __construct(
        public string $changeRequestId,
        public null|string $proposedValue,
    ) {
    }
}
