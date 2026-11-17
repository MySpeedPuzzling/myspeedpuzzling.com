<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\RoundEntryRef;

/**
 * One round's published official entries as one viewer may see them (OfficialEntrantsView): ranked over every ranked
 * entry, then the rows hidden from the viewer dropped without renumbering, private players masked.
 */
readonly final class OfficialRoundEntrants
{
    /**
     * @param list<PublishedRoundEntry> $entries the ranked entries the viewer may see, in ranking order (ties by name)
     */
    public function __construct(
        public string $roundId,
        public array $entries,
        // Every ranked entry of the round, whoever the viewer is (the rows hidden from them included)
        public int $rankedCount,
        // How many puzzles the round has (competition_round_puzzle rows, revealed or not)
        public int $roundPuzzlesCount,
        // The viewer is linked to an entry of the round - did not start and no result yet included; in a pair/team round
        // only as a member of a pair/team
        public bool $viewerHasEntry,
        // The viewer's own name and country - what "Add to my profile" compares unlinked names with
        public null|string $viewerName,
        public null|string $viewerCountry,
    ) {
    }

    /**
     * Nothing ranked in the round (or it is not published) - pages show no official results at all.
     */
    public function isEmpty(): bool
    {
        return $this->rankedCount === 0;
    }

    /**
     * The official place of an entry the viewer may see - null when it is not ranked, not published or hidden from them.
     */
    public function rankOf(RoundEntryRef $ref): null|int
    {
        foreach ($this->entries as $entry) {
            if ($entry->ref->toString() === $ref->toString()) {
                return $entry->rank;
            }
        }

        return null;
    }
}
