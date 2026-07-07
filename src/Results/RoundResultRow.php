<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class RoundResultRow
{
    /**
     * @param array<RoundResultMember> $members
     */
    public function __construct(
        public string $resultId,
        public int $rank,
        public null|string $entrantName,
        public null|int $secondsToSolve,
        public null|int $missingPieces,
        public null|string $participantId,
        public null|string $teamId,
        public array $members = [],
    ) {
    }

    public function isDnf(): bool
    {
        return $this->secondsToSolve === null;
    }
}
