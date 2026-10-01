<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\PlayerIdentification;
use SpeedPuzzling\Web\Results\PlayersPerCountry;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Value\CountryCode;

readonly final class GetPlayersPerCountry
{
    public function __construct(
        private Connection $database,
        private HiddenPlayers $hiddenPlayers,
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

        return array_map(static function (array $row): PlayersPerCountry {
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
     * Countries whose players page lists somebody for an anonymous visitor (the same rule as
     * byCountry() without a viewer). The page is noindex without players, so only these belong
     * in the sitemap.
     *
     * @return list<CountryCode>
     */
    public function countriesWithPublicPlayers(): array
    {
        $query = <<<SQL
SELECT DISTINCT country
FROM player
WHERE country IS NOT NULL
    AND is_private = false
ORDER BY country
SQL;

        /** @var list<string> $codes */
        $codes = $this->database
            ->executeQuery($query)
            ->fetchFirstColumn();

        $countries = [];

        foreach ($codes as $code) {
            $country = CountryCode::fromCode($code);

            if ($country !== null) {
                $countries[] = $country;
            }
        }

        return $countries;
    }

    /**
     * @return array<PlayerIdentification>
     */
    public function byCountry(CountryCode $countryCode): array
    {
        $notHidden = $this->hiddenPlayers->sqlExclude('player.id');

        $query = <<<SQL
SELECT
    id AS player_id,
    name AS player_name,
    code AS player_code,
    country AS player_country,
    avatar AS player_avatar
FROM player
WHERE player.country = :countryCode
    AND player.is_private = false
    {$notHidden}
ORDER BY name
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'countryCode' => $countryCode->name,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): PlayerIdentification {
            /**
             * @var array{
             *     player_id: string,
             *     player_code: string,
             *     player_name: null|string,
             *     player_country: null|string,
             *     player_avatar: null|string,
             * } $row
             */

            return PlayerIdentification::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * @return array<PlayersPerCountry>
     */
    public function forPuzzle(string $puzzleId): array
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

        return array_map(static function (array $row): PlayersPerCountry {
            /**
             * @var array{
             *     country: string,
             *     players_count: int,
             * } $row
             */

            return PlayersPerCountry::fromDatabaseRow($row);
        }, $data);
    }
}
