<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\Puzzler;

readonly final class SolvedPuzzleDetail
{
    public function __construct(
        public string $timeId,
        public null|string $teamId,
        public string $playerId,
        public string $puzzleId,
        public string $puzzleName,
        public null|string $puzzleAlternativeName,
        public string $manufacturerName,
        public string $manufacturerId,
        public int $piecesCount,
        public int $time,
        public null|string $puzzleImage,
        public null|string $comment,
        /** @var null|array<Puzzler> */
        public null|array $players,
        public DateTimeImmutable $finishedAt,
        public null|string $finishedPuzzlePhoto,
        public bool $firstAttempt,
        public null|string $competitionId,
    ) {
    }

    /**
     * @param array{
     *     time_id: string,
     *     team_id: null|string,
     *     player_id: string,
     *     puzzle_id: string,
     *     puzzle_name: string,
     *     puzzle_alternative_name: null|string,
     *     manufacturer_name: string,
     *     manufacturer_id: string,
     *     puzzle_image: null|string,
     *     time: int,
     *     pieces_count: int,
     *     comment: null|string,
     *     players: null|string,
     *     finished_at: string,
     *     finished_puzzle_photo: string,
     *     first_attempt: bool,
     *     competition_id: null|string,
     *  } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $players = null;
        if ($row['players'] !== null) {
            $players = Puzzler::createPuzzlersFromJson($row['players'], $row['player_id']);
        }

        return new self(
            timeId: $row['time_id'],
            teamId: $row['team_id'],
            playerId: $row['player_id'],
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            puzzleAlternativeName: $row['puzzle_alternative_name'],
            manufacturerName: $row['manufacturer_name'],
            manufacturerId: $row['manufacturer_id'],
            piecesCount: $row['pieces_count'],
            time: $row['time'],
            puzzleImage: $row['puzzle_image'],
            comment: $row['comment'],
            players: $players,
            finishedAt: new DateTimeImmutable($row['finished_at']),
            finishedPuzzlePhoto: $row['finished_puzzle_photo'],
            firstAttempt: $row['first_attempt'],
            competitionId: $row['competition_id'],
        );
    }
}
