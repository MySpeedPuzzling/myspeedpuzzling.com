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
     * @param list<PublishedChallengeRow> $challengeRows the `?challenge=` view (real events W4): the entries the viewer may
     *     see by their place in that challenge, the unranked after - empty without one
     */
    public function __construct(
        public array $entries,
        // The piece count of the round's one puzzle ("479 / 500 pcs") - null when the round has several or it is hidden
        public null|int $piecesCount,
        // The puzzle "Add to my profile" logs a time on - null when the round offers none (several puzzles, not revealed)
        public null|string $profilePuzzleId,
        // Every ranked entry of the round, whoever the viewer is (the rows hidden from them included)
        public int $rankedCount = 0,
        // "Is your name here? Connect it": the viewer is linked to no entry of the round, and some names are nobody's
        public bool $offersConnecting = false,
        // The challenge `?challenge=` asked for (a shown puzzle of a pick round of K ≥ 2) - null = the round's ranking
        public null|RoundPuzzleSlot $challenge = null,
        public array $challengeRows = [],
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
