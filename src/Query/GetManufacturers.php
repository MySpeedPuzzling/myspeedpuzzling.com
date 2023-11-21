<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\ManufacturerOverview;
use SpeedPuzzling\Web\Results\PuzzleOverview;

readonly final class GetManufacturers
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<ManufacturerOverview>
     */
    public function onlyApproved(): array
    {
        $query = <<<SQL
SELECT id AS manufacturer_id, name AS manufacturer_name, approved
FROM manufacturer
WHERE approved = true
ORDER BY name ASC
SQL;

        $data = $this->database
            ->executeQuery($query)
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
