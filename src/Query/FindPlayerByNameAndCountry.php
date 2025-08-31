<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Value\CountryCode;

readonly final class FindPlayerByNameAndCountry
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function find(string $name, null|CountryCode $country): null|string
    {
        $query = <<<SQL
SELECT id
FROM player
WHERE LOWER(name) = LOWER(:name) 
  AND (
    (:country IS NULL AND country IS NULL)
    OR
    (:country IS NOT NULL AND LOWER(country) = LOWER(:country))
  )
LIMIT 1
SQL;

        /** @var false|string $id */
        $id = $this->database
            ->executeQuery($query, [
                'name' => $name,
                'country' => $country?->name,
            ])
            ->fetchOne();

        if ($id === false) {
            return null;
        }

        return $id;
    }
}
