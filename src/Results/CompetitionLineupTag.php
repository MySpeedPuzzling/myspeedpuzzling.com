<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One printed line-up tag (GetCompetitionLineupTags): a pair/team for the whole competition, by its exact member set
 * (CompetitionLineupId) - the team name the organiser gave it, its members, and the table of the round printed for
 * (the chosen round, else the line-up's first round by schedule).
 */
readonly final class CompetitionLineupTag
{
    /**
     * @param list<CompetitionTagMember> $members
     * @param list<string> $roundNames every round the line-up plays, by schedule
     */
    public function __construct(
        public string $lineupId,
        public null|string $teamName,
        public array $members,
        public null|int $tableNumber,
        public string $roundName,
        public array $roundNames,
    ) {
    }

    public function label(): string
    {
        return $this->teamName ?? implode(' & ', array_map(static fn (CompetitionTagMember $member): string => $member->name, $this->members));
    }
}
