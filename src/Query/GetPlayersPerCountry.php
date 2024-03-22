<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Results\PlayerIdentification;
use SpeedPuzzling\Web\Results\PlayersPerCountry;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Results\PuzzleSolver;
use SpeedPuzzling\Web\Value\CountryCode;

readonly final class GetPlayersPerCountry
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<PlayersPerCountry>
     */
    public function count(): array
    {
        $query = <<<SQL
SELECT COUNT(id) AS players_count, country
FROM player
WHERE country IS NOT NULL
GROUP BY country
ORDER BY COUNT(id) DESC, country
SQL;

        $data = $this->database
            ->executeQuery($query)
            ->fetchAllAssociative();

        return array_map(static function(array $row): PlayersPerCountry {
            /**
             * @var array{
             *     country: string,
             *     players_count: int,
             * } $row
             */

            return PlayersPerCountry::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @return array<PlayerIdentification>
     */
    public function byCountry(CountryCode $countryCode): array
    {
        $query = <<<SQL
SELECT
    id AS player_id,
    name AS player_name,
    code AS player_code,
    country AS player_country
FROM player
WHERE player.country = :countryCode
ORDER BY name
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'countryCode' => $countryCode->name,
            ])
            ->fetchAllAssociative();

        return array_map(static function(array $row): PlayerIdentification {
            /**
             * @var array{
             *     player_id: string,
             *     player_code: string,
             *     player_name: null|string,
             *     player_country: null|string,
             * } $row
             */

            return PlayerIdentification::fromDatabaseRow($row);
        }, $data);
    }
}
