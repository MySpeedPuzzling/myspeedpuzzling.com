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

        // First approach: search in competition_participant
        if ($countryValue === null) {
            $competitionParticipantQuery = <<<SQL
SELECT player_id
FROM competition_participant
WHERE LOWER(name) = LOWER(:name) 
  AND country IS NULL
  AND player_id IS NOT NULL
LIMIT 1
SQL;
            $parameters = ['name' => $name];
        } else {
            $competitionParticipantQuery = <<<SQL
SELECT player_id
FROM competition_participant
WHERE LOWER(name) = LOWER(:name) 
  AND LOWER(country) = LOWER(:country)
  AND player_id IS NOT NULL
LIMIT 1
SQL;
            $parameters = ['name' => $name, 'country' => $countryValue];
        }

        /** @var false|string $playerId */
        $playerId = $this->database
            ->executeQuery($competitionParticipantQuery, $parameters)
            ->fetchOne();

        // Early return if found in competition_participant
        if ($playerId !== false) {
            return $playerId;
        }

        // Second approach: search in player table
        if ($countryValue === null) {
            $playerQuery = <<<SQL
SELECT id
FROM player
WHERE LOWER(name) = LOWER(:name) 
  AND country IS NULL
LIMIT 1
SQL;
        } else {
            $playerQuery = <<<SQL
SELECT id
FROM player
WHERE LOWER(name) = LOWER(:name) 
  AND LOWER(country) = LOWER(:country)
LIMIT 1
SQL;
        }

        /** @var false|string $id */
        $id = $this->database
            ->executeQuery($playerQuery, $parameters)
            ->fetchOne();

        if ($id === false) {
            return null;
        }

        return $id;
    }
}
