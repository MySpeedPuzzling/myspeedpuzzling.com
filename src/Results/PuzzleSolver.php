<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\CountryCode;

readonly final class PuzzleSolver
{
    public function __construct(
        public string $puzzleId,
        public string $playerId,
        public string $playerName,
        public null|CountryCode $playerCountry,
        public string $timeId,
        public int $time,
        public DateTimeImmutable $finishedAt,
        public bool $firstAttempt,
        public bool $isOldest = false,
    ) {
    }

    /**
     * @param array{
     *     puzzle_id: string,
     *     player_id: string,
     *     player_name: string,
     *     player_country: null|string,
     *     time_id: string,
     *     time: int,
     *     finished_at: string,
     *     first_attempt: bool,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            puzzleId: $row['puzzle_id'],
            playerId: $row['player_id'],
            playerName: $row['player_name'],
            playerCountry: CountryCode::fromCode($row['player_country']),
            timeId: $row['time_id'],
            time: $row['time'],
            finishedAt: new DateTimeImmutable($row['finished_at']),
            firstAttempt: $row['first_attempt'],
        );
    }

    public function makeOldest(): self
    {
        return new self(
            puzzleId: $this->puzzleId,
            playerId: $this->playerId,
            playerName: $this->playerName,
            playerCountry: $this->playerCountry,
            timeId: $this->timeId,
            time: $this->time,
            finishedAt: $this->finishedAt,
            firstAttempt: $this->firstAttempt,
            isOldest: true,
        );
    }
}
