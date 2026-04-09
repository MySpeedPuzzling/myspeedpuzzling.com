<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class RoundTeamDetail
{
    /**
     * @param array<RoundTeamMember> $members
     */
    public function __construct(
        public string $id,
        public null|string $name,
        public array $members,
    ) {
    }
}
