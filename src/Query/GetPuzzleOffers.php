<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\PuzzleOffer;

readonly final class GetPuzzleOffers
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<PuzzleOffer>
     */
    public function byPuzzle(string $puzzleId): array
    {
        $query = <<<SQL
SELECT
    ci.id AS item_id,
    ci.player_id,
    p.name AS player_name,
    p.code AS player_code,
    p.country AS player_country,
    ci.comment,
    ci.price,
    ci.currency,
    ci.condition,
    ci.added_at,
    c.system_type
FROM puzzle_collection_item ci
INNER JOIN puzzle_collection c ON c.id = ci.collection_id
INNER JOIN player p ON p.id = ci.player_id
WHERE ci.puzzle_id = :puzzleId
  AND c.system_type = 'for_sale'
ORDER BY ci.added_at DESC
SQL;

        /** @var array<array{item_id: string, player_id: string, player_name: null|string, player_code: string, player_country: null|string, comment: null|string, price: null|string, currency: null|string, condition: null|string, added_at: string, system_type: string}> */
        $rows = $this->database->fetchAllAssociative($query, [
            'puzzleId' => $puzzleId,
        ]);

        return array_map(static fn(array $row): PuzzleOffer => PuzzleOffer::fromDatabaseRow($row), $rows);
    }

    public function countByPuzzle(string $puzzleId): int
    {
        $query = <<<SQL
SELECT COUNT(*)
FROM puzzle_collection_item ci
INNER JOIN puzzle_collection c ON c.id = ci.collection_id
WHERE ci.puzzle_id = :puzzleId
  AND c.system_type = 'for_sale'
SQL;

        /** @var int|string|false */
        $result = $this->database->fetchOne($query, [
            'puzzleId' => $puzzleId,
        ]);

        return (int) $result;
    }
}
