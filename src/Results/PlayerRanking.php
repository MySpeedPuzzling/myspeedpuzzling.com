<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class PlayerRanking
{
    public function __construct(
        public string $playerId,
        public int $rank,
        public string $puzzleId,
        public string $puzzleName,
        public null|string $puzzleAlternativeName,
        public string $manufacturerName,
        public int $piecesCount,
        public int $time,
        public null|string $puzzleImage,
    ) {
    }

    /**
     * @param array{
     *     player_id: string,
     *     rank: int,
     *     time: int,
     *     puzzle_id: string,
     *     puzzle_name: string,
     *     puzzle_alternative_name: null|string,
     *     pieces_count: int,
     *     puzzle_image: null|string,
     *     manufacturer_name: string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            playerId: $row['player_id'],
            rank: $row['rank'],
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            puzzleAlternativeName: $row['puzzle_alternative_name'],
            manufacturerName: $row['manufacturer_name'],
            piecesCount: $row['pieces_count'],
            time: $row['time'],
            puzzleImage: $row['puzzle_image'],
        );
    }
}
