<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

readonly final class TeamSuggestion
{
    public function __construct(
        public string $teamId,
        public null|string $name,
        // Everybody including the viewer: 2 = pair, 3+ = team
        public int $size,
        public int $timesCount,
        public null|DateTimeImmutable $lastTogetherAt,
        public float $score,
        /** @var list<string> Member keys of everybody but the viewer - see PersonSuggestion::$key */
        public array $memberKeys,
        // The viewer keeps it out of their shortcuts (PuzzlingTeamArchive)
        public bool $archived = false,
    ) {
    }
}
