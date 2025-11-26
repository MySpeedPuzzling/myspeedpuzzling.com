<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\CollectionItemOverview;

readonly final class GetUnsolvedPuzzles
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<CollectionItemOverview>
     */
    public function byPlayerId(string $playerId): array
    {
        $query = <<<SQL
SELECT
    p.id as puzzle_id,
    p.name as puzzle_name,
    p.alternative_name as puzzle_alternative_name,
    p.identification_number as puzzle_identification_number,
    p.ean,
    p.pieces_count,
    p.image,
    m.name as manufacturer_name,
    MIN(ci.added_at) as added_at
FROM collection_item ci
JOIN puzzle p ON ci.puzzle_id = p.id
LEFT JOIN manufacturer m ON p.manufacturer_id = m.id
LEFT JOIN puzzle_solving_time pst ON (
    pst.player_id = ci.player_id
    AND pst.puzzle_id = ci.puzzle_id
)
WHERE ci.player_id = :playerId
  AND pst.id IS NULL
GROUP BY p.id, p.name, p.alternative_name, p.identification_number, p.ean, p.pieces_count, p.image, m.name
ORDER BY added_at DESC
SQL;

        $data = $this->database
            ->executeQuery($query, ['playerId' => $playerId])
            ->fetchAllAssociative();

        return array_map(static function (array $row): CollectionItemOverview {
            /** @var array{
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_name: string|null,
             *     puzzle_identification_number: string|null,
             *     ean: string|null,
             *     pieces_count: int,
             *     image: string|null,
             *     manufacturer_name: string|null,
             *     added_at: string,
             * } $row
             */

            return new CollectionItemOverview(
                collectionItemId: $row['puzzle_id'],
                puzzleId: $row['puzzle_id'],
                puzzleName: $row['puzzle_name'],
                puzzleAlternativeName: $row['puzzle_alternative_name'],
                puzzleIdentificationNumber: $row['puzzle_identification_number'],
                ean: $row['ean'],
                piecesCount: $row['pieces_count'],
                manufacturerName: $row['manufacturer_name'],
                image: $row['image'],
                comment: null,
                addedAt: new DateTimeImmutable($row['added_at']),
            );
        }, $data);
    }

    public function countByPlayerId(string $playerId): int
    {
        // Count unique puzzles (not collection items) that haven't been solved
        $query = <<<SQL
SELECT COUNT(DISTINCT ci.puzzle_id) as item_count
FROM collection_item ci
LEFT JOIN puzzle_solving_time pst ON (
    pst.player_id = ci.player_id
    AND pst.puzzle_id = ci.puzzle_id
)
WHERE ci.player_id = :playerId
  AND pst.id IS NULL
SQL;

        $result = $this->database
            ->executeQuery($query, ['playerId' => $playerId])
            ->fetchOne();

        return is_numeric($result) ? (int) $result : 0;
    }
}
