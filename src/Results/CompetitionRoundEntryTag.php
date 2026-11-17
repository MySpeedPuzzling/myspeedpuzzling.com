<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One printed round entry tag (GetRoundEntryTags): a person of a solo round (CompetitionParticipantRound) or a pair/team
 * of a pair/team round (CompetitionTeam) - the id its QR carries (`/live/{cid}/e/{entryId}`), the team name, the people
 * and the table of that round.
 */
readonly final class CompetitionRoundEntryTag
{
    /**
     * @param list<CompetitionTagMember> $members
     */
    public function __construct(
        public string $entryId,
        public null|string $teamName,
        public array $members,
        public null|int $tableNumber,
    ) {
    }

    public function label(): string
    {
        return $this->teamName ?? implode(' & ', array_map(static fn (CompetitionTagMember $member): string => $member->name, $this->members));
    }
}
