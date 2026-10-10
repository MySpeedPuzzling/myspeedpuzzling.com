<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * One result of a player in a digest period - solo, or a pair/team result the player is a registered member of.
 */
readonly final class PeriodResult
{
    public function __construct(
        public string $timeId,
        public string $puzzleId,
        public string $puzzleName,
        public string $brandName,
        public int $piecesCount,
        public null|string $puzzleImage,
        public null|int $secondsToSolve,
        public DateTimeImmutable $solvedAt,
        public string $puzzlingType,
        public int $puzzlersCount,
        public bool $firstAttempt,
        /** Faster than every earlier solo time of the player on this puzzle (there was at least one) */
        public bool $personalBest,
        /** Not counted anywhere until verified (docs/features/suspicious-times.md) - only listed for the owner */
        public bool $awaitingVerification,
        public int $xp,
        /** The player's current place on the puzzle's solo leaderboard (the "Your week" page only) */
        public null|int $rank = null,
        public null|int $rankTotal = null,
    ) {
    }

    public function isSolo(): bool
    {
        return $this->puzzlingType === 'solo';
    }

    public function withRank(null|int $rank, null|int $rankTotal): self
    {
        return new self(
            timeId: $this->timeId,
            puzzleId: $this->puzzleId,
            puzzleName: $this->puzzleName,
            brandName: $this->brandName,
            piecesCount: $this->piecesCount,
            puzzleImage: $this->puzzleImage,
            secondsToSolve: $this->secondsToSolve,
            solvedAt: $this->solvedAt,
            puzzlingType: $this->puzzlingType,
            puzzlersCount: $this->puzzlersCount,
            firstAttempt: $this->firstAttempt,
            personalBest: $this->personalBest,
            awaitingVerification: $this->awaitingVerification,
            xp: $this->xp,
            rank: $rank,
            rankTotal: $rankTotal,
        );
    }
}
