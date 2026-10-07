<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\OfficialEntryProfileState;
use SpeedPuzzling\Web\Value\RoundEntryRef;
use SpeedPuzzling\Web\Value\RoundEntryResult;

/**
 * One row of a round's published official results (GetPublishedRoundResults) - a ranked entry: a person of a solo
 * round or a pair/team. The rank was computed over every ranked entry of the round, before the rows hidden from the
 * viewer were dropped, so it is the official place whatever the viewer may see.
 */
readonly final class PublishedRoundEntry
{
    /**
     * @param list<PublishedRoundEntrant> $entrants the person, or the members of the pair/team (by name)
     */
    public function __construct(
        public RoundEntryRef $ref,
        public int $rank,
        public RoundEntryResult $result,
        public bool $qualified,
        public bool $isTeam,
        public null|string $teamName,
        public array $entrants,
        // The viewer is linked to this entry: the person, or a member of the pair/team
        public bool $isViewers,
        public null|OfficialEntryProfileState $profileState = null,
    ) {
    }

    public function hasLinkedPlayer(): bool
    {
        foreach ($this->entrants as $entrant) {
            if ($entrant->linkedPlayerId !== null) {
                return true;
            }
        }

        return false;
    }

    public function withProfileState(null|OfficialEntryProfileState $profileState): self
    {
        return new self(
            ref: $this->ref,
            rank: $this->rank,
            result: $this->result,
            qualified: $this->qualified,
            isTeam: $this->isTeam,
            teamName: $this->teamName,
            entrants: $this->entrants,
            isViewers: $this->isViewers,
            profileState: $profileState,
        );
    }
}
