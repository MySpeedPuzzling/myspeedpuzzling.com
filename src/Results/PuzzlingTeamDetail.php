<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class PuzzlingTeamDetail
{
    public function __construct(
        public string $teamId,
        public null|string $name,
        public int $size,
        /** @var list<PuzzlingTeamMemberView> */
        public array $members,
    ) {
    }

    public function isPair(): bool
    {
        return $this->size === 2;
    }

    public function hasMember(null|string $playerId): bool
    {
        if ($playerId === null) {
            return false;
        }

        foreach ($this->members as $member) {
            if ($member->playerId === $playerId) {
                return true;
            }
        }

        return false;
    }
}
