<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What the seller's answer did: how many listings changed, and the yeses refused because the listing is reserved for
 * somebody else meanwhile (they stay waiting - the seller removes that reservation first).
 */
final readonly class ListingRequestAnswerOutcome
{
    public function __construct(
        public int $changed,
        public int $reservedForSomebodyElse = 0,
        public null|string $conversationId = null,
    ) {
    }
}
