<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * A round's published official results as the public round page shows them to one viewer (GetPublishedRoundResults,
 * docs/features/competitions-management/official-results.md).
 */
readonly final class PublishedRoundResults
{
    /**
     * @param list<PublishedRoundEntry> $entries the ranked entries the viewer may see, in ranking order
     */
    public function __construct(
        public array $entries,
        // The piece count of the round's one puzzle ("479 / 500 pcs") - null when the round has several or it is hidden
        public null|int $piecesCount,
        // The puzzle "Add to my profile" logs a time on - null when the round offers none (several puzzles, not revealed)
        public null|string $profilePuzzleId,
    ) {
    }

    public function hasQualified(): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry->qualified) {
                return true;
            }
        }

        return false;
    }

    public function entry(string $ref): null|PublishedRoundEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->ref->toString() === strtolower($ref)) {
                return $entry;
            }
        }

        return null;
    }
}
