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
        public null|string $playerName,
        public string $playerCode,
        public null|CountryCode $playerCountry,
        public int $time,
        public DateTimeImmutable $finishedAt,
        public bool $firstAttempt,
        public bool $isPrivate,
        public null|string $competitionId,
        public null|string $competitionShortcut,
        public null|string $competitionName,
        public null|string $competitionSlug,
    ) {
    }

    /**
     * @param array{
     *     puzzle_id: string,
     *     player_id: string,
     *     player_name: null|string,
     *     player_code: string,
     *     player_country: null|string,
     *     time: int,
     *     finished_at: string,
     *     first_attempt: bool,
     *     is_private: bool,
     *     competition_id: null|string,
     *     competition_shortcut: null|string,
     *     competition_name: null|string,
     *     competition_slug: null|string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            puzzleId: $row['puzzle_id'],
            playerId: $row['player_id'],
            playerName: $row['player_name'],
            playerCode: strtoupper($row['player_code']),
            playerCountry: CountryCode::fromCode($row['player_country']),
            time: $row['time'],
            finishedAt: new DateTimeImmutable($row['finished_at']),
            firstAttempt: $row['first_attempt'],
            isPrivate: $row['is_private'],
            competitionId: $row['competition_id'],
            competitionShortcut: $row['competition_shortcut'],
            competitionName: $row['competition_name'],
            competitionSlug: $row['competition_slug'],
        );
    }
}
