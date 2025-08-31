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

    public function find(string $name, CountryCode $country): null|string
    {
        $query = <<<SQL
SELECT id
FROM player
WHERE LOWER(name) = LOWER(:name) 
  AND LOWER(country) = LOWER(:country)
LIMIT 1
SQL;

        /** @var false|array{id: string} $data */
        $data = $this->database
            ->executeQuery($query, [
                'name' => $name,
                'country' => $country->name,
            ])
            ->fetchAssociative();

        if ($data === false) {
            return null;
        }

        return $data['id'];
    }
}
