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
    public function onlyApprovedOrAddedByPlayer(string $playerId): array
    {
        $query = <<<SQL
SELECT id AS manufacturer_id, name AS manufacturer_name, approved
FROM manufacturer
WHERE (approved = true OR added_by_user_id = :playerId)
ORDER BY name ASC
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchAllAssociative();

        return array_map(static function(array $row): ManufacturerOverview {
            /**
             * @var array{
             *     manufacturer_id: string,
             *     manufacturer_name: string,
             *     approved: bool
             * } $row
             */
            return ManufacturerOverview::fromDatabaseRow($row);
        }, $data);
    }
}
