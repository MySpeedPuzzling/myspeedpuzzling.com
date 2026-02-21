<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\Puzzler;

readonly final class SolvedPuzzle
{
    public function __construct(
        public string $timeId,
        public string $playerId,
        public null|string $playerName,
        public string $playerCode,
        public null|CountryCode $playerCountry,
        public string $puzzleId,
        public string $puzzleName,
        public null|string $puzzleAlternativeName,
        public string $manufacturerName,
        public int $piecesCount,
        public null|int $time,
        public null|string $puzzleImage,
        public null|string $comment,
        public DateTimeImmutable $trackedAt,
        public null|string $finishedPuzzlePhoto,
        public null|string $teamId,
        /** @var null|array<Puzzler> */
        public null|array $players,
        public int $solvedTimes,
        public null|string $puzzleIdentificationNumber,
        public null|DateTimeImmutable $finishedAt,
        public bool $firstAttempt,
        public bool $unboxed,
        public bool $isPrivate,
        public null|string $competitionId,
        public null|string $competitionShortcut,
        public null|string $competitionName,
        public null|string $competitionSlug,
        public bool $suspicious = false,
    ) {
    }

    /**
     * @param array{
     *     time_id: string,
     *     player_id: string,
     *     player_name: null|string,
     *     player_code: string,
     *     player_country: null|string,
     *     puzzle_id: string,
     *     puzzle_name: string,
     *     puzzle_alternative_name: null|string,
     *     manufacturer_name: string,
     *     puzzle_image: null|string,
     *     time: null|int,
     *     pieces_count: int,
     *     comment: null|string,
     *     tracked_at: string,
     *     finished_puzzle_photo: null|string,
     *     puzzle_identification_number: null|string,
     *     team_id?: null|string,
     *     players?: null|string|array<Puzzler>,
     *     solved_times?: int,
     *     finished_at: null|string,
     *     first_attempt: bool,
     *     unboxed: bool,
     *     is_private?: bool,
     *     competition_id: null|string,
     *     competition_name: null|string,
     *     competition_shortcut: null|string,
     *     competition_slug: null|string,
     *     suspicious?: bool,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $players = null;

        if (is_array($row['players'] ?? null)) {
            $players = $row['players'];
        }

        if (is_string($row['players'] ?? null)) {
            $players = Puzzler::createPuzzlersFromJson($row['players']);
        }

        return new self(
            timeId: $row['time_id'],
            playerId: $row['player_id'],
            playerName: $row['player_name'],
            playerCode: strtoupper($row['player_code']),
            playerCountry: CountryCode::fromCode($row['player_country']),
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            puzzleAlternativeName: $row['puzzle_alternative_name'],
            manufacturerName: $row['manufacturer_name'],
            piecesCount: $row['pieces_count'],
            time: $row['time'],
            puzzleImage: $row['puzzle_image'],
            comment: $row['comment'],
            trackedAt: new DateTimeImmutable($row['tracked_at']),
            finishedPuzzlePhoto: $row['finished_puzzle_photo'],
            teamId: $row['team_id'] ?? null,
            players: $players,
            solvedTimes: $row['solved_times'] ?? 1,
            puzzleIdentificationNumber: $row['puzzle_identification_number'],
            finishedAt: $row['finished_at'] !== null ? new DateTimeImmutable($row['finished_at']) : null,
            firstAttempt: $row['first_attempt'],
            unboxed: $row['unboxed'],
            isPrivate: $row['is_private'] ?? false,
            competitionId: $row['competition_id'],
            competitionShortcut: $row['competition_shortcut'],
            competitionName: $row['competition_name'],
            competitionSlug: $row['competition_slug'],
            suspicious: $row['suspicious'] ?? false,
        );
    }
}
