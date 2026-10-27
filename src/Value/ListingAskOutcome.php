<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What one "Send" of the listing request sheet did: how many sellers were asked about how many listings, and the
 * listings left out with their reason.
 */
final readonly class ListingAskOutcome
{
    /**
     * @param array<string, ListingAskSkipReason> $skipped listing id => why it was not asked for
     */
    public function __construct(
        public int $requestsSent,
        public int $listingsAsked,
        public array $skipped = [],
    ) {
    }

    public function skippedFor(ListingAskSkipReason ...$reasons): int
    {
        return count(array_filter($this->skipped, static fn (ListingAskSkipReason $skipped): bool => in_array($skipped, $reasons, true)));
    }
}
