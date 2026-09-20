<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class RelatedPuzzlingTeam
{
    public function __construct(
        public string $teamId,
        public null|string $name,
        public int $size,
        public int $timesCount,
        /** @var list<PuzzlingTeamMemberView> */
        public array $members,
    ) {
    }
}
