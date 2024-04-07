<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\CountryCode;

readonly final class PuzzleSolver
{
    public function __construct(
        public string $playerId,
        public string $playerName,
        public null|CountryCode $playerCountry,
        public int $time,
        public DateTimeImmutable $finishedAt,
        public bool $firstAttempt,
    ) {
    }

    /**
     * @param array{
     *     player_id: string,
     *     player_name: string,
     *     player_country: null|string,
     *     time: int,
     *     finished_at: string,
     *     first_attempt: bool,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            playerId: $row['player_id'],
            playerName: $row['player_name'],
            playerCountry: CountryCode::fromCode($row['player_country']),
            time: $row['time'],
            finishedAt: new DateTimeImmutable($row['finished_at']),
            firstAttempt: $row['first_attempt'],
        );
    }
}
