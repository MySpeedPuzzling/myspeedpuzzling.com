<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\ManufacturerOverview;

readonly final class GetManufacturers
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<ManufacturerOverview>
     */
    public function onlyApprovedOrAddedByPlayer(null|string $playerId = null): array
    {
        $query = <<<SQL
SELECT
    manufacturer.id AS manufacturer_id,
    manufacturer.name AS manufacturer_name,
    manufacturer.approved AS manufacturer_approved,
    COUNT(puzzle.id) AS puzzles_count
FROM manufacturer
LEFT JOIN puzzle ON puzzle.manufacturer_id = manufacturer.id
WHERE (manufacturer.approved = true OR manufacturer.added_by_user_id = :playerId)
GROUP BY manufacturer.id
ORDER BY COUNT(puzzle.id) DESC, manufacturer.name ASC
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchAllAssociative();

        return array_map(static function(array $row): ManufacturerOverview {
            /**
             * @var array{
             *      manufacturer_id: string,
             *      manufacturer_name: string,
             *      manufacturer_approved: bool,
             *      puzzles_count: int,
             * } $row
             */
            return ManufacturerOverview::fromDatabaseRow($row);
        }, $data);
    }
}
