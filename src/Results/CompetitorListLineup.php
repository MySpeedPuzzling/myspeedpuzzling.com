<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One pair/team "for the whole competition" on the public competitor list (GetCompetitorList) - a line-up, the exact
 * member set of its pairs/teams (CompetitionLineupId, the same rule the line-up page resolves). Its row links the
 * line-up page (`g`).
 */
readonly final class CompetitorListLineup
{
    /**
     * @param list<PublishedRoundEntrant> $members by name, masked like the public round page
     */
    public function __construct(
        public string $lineupId,
        // The team name of its latest round that has one - null: the members' names stand for it
        public null|string $teamName,
        public array $members,
        // A team round (3+ people) rather than a pair
        public bool $isTeam,
        // The viewer is one of the members - "You"
        public bool $isViewers,
        // SearchText::fold() of the team name and the members' names - the list's filter reads it
        public string $search,
    ) {
    }

    public function membersNames(): string
    {
        return implode(' & ', array_map(static fn (PublishedRoundEntrant $member): string => $member->playerName, $this->members));
    }

    public function displayName(): string
    {
        return $this->teamName ?? $this->membersNames();
    }
}
