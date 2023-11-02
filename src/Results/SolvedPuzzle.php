<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class SolvedPuzzle
{
    public function __construct(
        public string $puzzleId,
        public string $puzzleName,
        public int $piecesCount,
        public int $time,
        public int $playersCount,
        public null|string $groupName,
    ) {
    }

    /**
     * @param array{
     *     puzzle_id: string,
     *     puzzle_name: string,
     *     players_count: int,
     *     time: int,
     *     pieces_count: int,
     *     group_name: null|string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            piecesCount: $row['pieces_count'],
            time: $row['time'],
            playersCount: $row['players_count'],
            groupName: $row['group_name'],
        );
    }
}
