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
        /** An unfinished competition result: pieces placed when the round's time ran out - not a solve (CountsAsSolve) */
        public null|int $piecesPlaced = null,
        /** The time spent on an unfinished result */
        public null|int $secondsSpent = null,
    ) {
    }

    public function isSolo(): bool
    {
        return $this->puzzlingType === 'solo';
    }

    /**
     * An unfinished result is not a solve - no puzzle in the counts (Query\CountsAsSolve).
     */
    public function isSolve(): bool
    {
        return $this->piecesPlaced === null;
    }

    /**
     * Pieces puzzled: the puzzle's pieces, or the pieces placed of an unfinished result.
     */
    public function piecesPuzzled(): int
    {
        return $this->piecesPlaced ?? $this->piecesCount;
    }

    /**
     * Seconds puzzled: the time to solve, or the time spent on an unfinished result - null for a relax solve.
     */
    public function secondsPuzzled(): null|int
    {
        return $this->secondsToSolve ?? $this->secondsSpent;
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
            piecesPlaced: $this->piecesPlaced,
            secondsSpent: $this->secondsSpent,
        );
    }
}
