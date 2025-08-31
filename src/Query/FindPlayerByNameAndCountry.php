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
        $countryValue = $country?->name;

        if ($countryValue === null) {
            $query = <<<SQL
SELECT id
FROM player
WHERE LOWER(name) = LOWER(:name) 
  AND country IS NULL
LIMIT 1
SQL;
            $parameters = ['name' => $name];
        } else {
            $query = <<<SQL
SELECT id
FROM player
WHERE LOWER(name) = LOWER(:name) 
  AND LOWER(country) = LOWER(:country)
LIMIT 1
SQL;
            $parameters = ['name' => $name, 'country' => $countryValue];
        }

        /** @var false|string $id */
        $id = $this->database
            ->executeQuery($query, $parameters)
            ->fetchOne();

        if ($id === false) {
            return null;
        }

        return $id;
    }
}
