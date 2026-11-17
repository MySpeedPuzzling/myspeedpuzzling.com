<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\RoundEntryPuzzles;
use SpeedPuzzling\Web\Value\RoundEntryRef;
use SpeedPuzzling\Web\Value\RoundEntryResult;

/**
 * One entry of a studio round (GetAnalyticsStudioEntrants): a solo entrant or a pair/team, with its stored result -
 * what the public may see of that result is AnalyticsStudioLiveResults' business.
 */
readonly final class AnalyticsStudioEntrant
{
    /**
     * @param list<AnalyticsStudioMember> $members
     */
    public function __construct(
        public RoundEntryRef $ref,
        public null|string $teamName,
        public null|int $tableNumber,
        public RoundEntryResult $result,
        public null|RoundEntryPuzzles $puzzles,
        public array $members,
    ) {
    }

    public function key(): string
    {
        return $this->ref->id;
    }

    public function isTeam(): bool
    {
        return $this->ref->isTeam();
    }

    /**
     * @return list<string>
     */
    public function analysablePlayerIds(): array
    {
        $ids = [];
        foreach ($this->members as $member) {
            $playerId = $member->analysablePlayerId();
            if ($playerId !== null) {
                $ids[] = $playerId;
            }
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    public function participantIds(): array
    {
        return array_map(static fn (AnalyticsStudioMember $member): string => $member->participantId, $this->members);
    }

    public function sortName(): string
    {
        if ($this->teamName !== null && trim($this->teamName) !== '') {
            return $this->teamName;
        }

        return implode(', ', array_map(static fn (AnalyticsStudioMember $member): string => $member->entrant->playerName, $this->members));
    }
}
