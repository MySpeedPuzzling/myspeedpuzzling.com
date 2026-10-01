<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\Puzzler;

/**
 * Every result of one subject on one puzzle, opened from one of its times (docs/features/puzzle-result-detail.md).
 * The subject is derived from the time: its solo player, or its exact pair/team (puzzling_team).
 */
readonly final class PuzzleResultDetail
{
    public function __construct(
        public string $focusTimeId,
        public string $puzzleId,
        public string $puzzleName,
        public null|string $puzzleAlternativeName,
        public null|string $puzzleImage,
        public null|float $puzzleImageRatio,
        public int $piecesCount,
        public string $manufacturerName,
        // 'solo', 'duo' or 'team'
        public string $puzzlingType,
        // The solo player; for a pair/team whoever tracked the opened time
        public Puzzler $player,
        // Pair/team only
        public null|string $teamId,
        public null|string $teamName,
        /** @var null|list<Puzzler> */
        public null|array $players,
        /**
         * Newest first
         *
         * @var list<PuzzleResultAttempt>
         */
        public array $attempts,
        public null|PuzzleResultAttempt $bestAttempt,
        public null|PuzzleResultStanding $standing = null,
    ) {
    }

    public function isGroup(): bool
    {
        return $this->players !== null;
    }

    public function membersCount(): int
    {
        return $this->players === null ? 1 : max(1, count($this->players));
    }

    /**
     * @return list<PuzzleResultAttempt>
     */
    public function timedAttempts(): array
    {
        return array_values(array_filter(
            $this->attempts,
            static fn (PuzzleResultAttempt $attempt): bool => $attempt->time !== null,
        ));
    }

    public function withStanding(null|PuzzleResultStanding $standing): self
    {
        return new self(
            focusTimeId: $this->focusTimeId,
            puzzleId: $this->puzzleId,
            puzzleName: $this->puzzleName,
            puzzleAlternativeName: $this->puzzleAlternativeName,
            puzzleImage: $this->puzzleImage,
            puzzleImageRatio: $this->puzzleImageRatio,
            piecesCount: $this->piecesCount,
            manufacturerName: $this->manufacturerName,
            puzzlingType: $this->puzzlingType,
            player: $this->player,
            teamId: $this->teamId,
            teamName: $this->teamName,
            players: $this->players,
            attempts: $this->attempts,
            bestAttempt: $this->bestAttempt,
            standing: $standing,
        );
    }
}
