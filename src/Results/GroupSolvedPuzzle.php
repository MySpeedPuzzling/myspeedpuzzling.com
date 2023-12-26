<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\Puzzler;

readonly final class GroupSolvedPuzzle
{
    public function __construct(
        public string $timeId,
        public null|string $teamId,
        public string $addedByPlayerId,
        public string $puzzleId,
        public string $puzzleName,
        public null|string $puzzleAlternativeName,
        public string $manufacturerName,
        public int $piecesCount,
        public int $time,
        public null|string $puzzleImage,
        public null|string $comment,
        /** @var array<Puzzler> */
        public array $players,
        public null|string $finishedPuzzlePhoto,
    ) {
    }

    /**
     * @param array{
     *     time_id: string,
     *     team_id: null|string,
     *     added_by_player_id: string,
     *     puzzle_id: string,
     *     puzzle_name: string,
     *     puzzle_alternative_name: null|string,
     *     manufacturer_name: string,
     *     puzzle_image: null|string,
     *     time: int,
     *     pieces_count: int,
     *     comment: null|string,
     *     players: string,
     *     finished_puzzle_photo: null|string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $players = Puzzler::createPuzzlersFromJson($row['players']);

        return new self(
            timeId: $row['time_id'],
            teamId: $row['team_id'],
            addedByPlayerId: $row['added_by_player_id'],
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            puzzleAlternativeName: $row['puzzle_alternative_name'],
            manufacturerName: $row['manufacturer_name'],
            piecesCount: $row['pieces_count'],
            time: $row['time'],
            puzzleImage: $row['puzzle_image'],
            comment: $row['comment'],
            players: $players,
            finishedPuzzlePhoto: $row['finished_puzzle_photo'],
        );
    }
}
