<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class SolvedPuzzle
{
    public function __construct(
        public string $timeId,
        public string $playerId,
        public string $puzzleId,
        public string $puzzleName,
        public null|string $puzzleAlternativeName,
        public string $manufacturerName,
        public int $piecesCount,
        public int $time,
        public int $playersCount,
        public null|string $groupName,
        public null|string $puzzleImage,
        public null|string $comment,
    ) {
    }

    /**
     * @param array{
     *     time_id: string,
     *     player_id: string,
     *     puzzle_id: string,
     *     puzzle_name: string,
     *     puzzle_alternative_name: null|string,
     *     manufacturer_name: string,
     *     puzzle_image: null|string,
     *     players_count: int,
     *     time: int,
     *     pieces_count: int,
     *     group_name: null|string,
     *     comment: null|string
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            timeId: $row['time_id'],
            playerId: $row['player_id'],
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            puzzleAlternativeName: $row['puzzle_alternative_name'],
            manufacturerName: $row['manufacturer_name'],
            piecesCount: $row['pieces_count'],
            time: $row['time'],
            playersCount: $row['players_count'],
            groupName: $row['group_name'],
            puzzleImage: $row['puzzle_image'],
            comment: $row['comment'],
        );
    }
}
