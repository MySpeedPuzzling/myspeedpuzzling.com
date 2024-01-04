<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\Puzzler;

readonly final class SolvedPuzzle
{
    public function __construct(
        public string $timeId,
        public string $playerId,
        public string $playerName,
        public string $puzzleId,
        public string $puzzleName,
        public null|string $puzzleAlternativeName,
        public string $manufacturerName,
        public int $piecesCount,
        public int $time,
        public null|string $puzzleImage,
        public null|string $comment,
        public DateTimeImmutable $trackedAt,
        public null|string $finishedPuzzlePhoto,
        public null|string $teamId,
        /** @var null|array<Puzzler> */
        public null|array $players,
        public int $solvedTimes,
    ) {
    }

    /**
     * @param array{
     *     time_id: string,
     *     player_id: string,
     *     player_name: null|string,
     *     puzzle_id: string,
     *     puzzle_name: string,
     *     puzzle_alternative_name: null|string,
     *     manufacturer_name: string,
     *     puzzle_image: null|string,
     *     time: int,
     *     pieces_count: int,
     *     comment: null|string,
     *     tracked_at: string,
     *     finished_puzzle_photo: null|string,
     *     team_id?: null|string,
     *     players?: null|string,
     *     solved_times?: int,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $players = null;
        if (is_string($row['players'] ?? null)) {
            $players = Puzzler::createPuzzlersFromJson($row['players']);
        }

        return new self(
            timeId: $row['time_id'],
            playerId: $row['player_id'],
            playerName: $row['player_name'] ?? '',
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
        );
    }
}
