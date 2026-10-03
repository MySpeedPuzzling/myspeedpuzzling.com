<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\PlayersPerCountry;
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
     * Countries whose players page lists somebody for an anonymous visitor (public profiles,
     * GetPlayersDirectory). The page is noindex without players, so only these belong in the
     * sitemap.
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
     * Whether the country page lists anybody for an anonymous visitor - the page is noindex without public players
     * (the same rule as countriesWithPublicPlayers(), which feeds the sitemap). Independent of the viewer on purpose.
     */
    public function hasPublicPlayers(CountryCode $countryCode): bool
    {
        $query = <<<SQL
SELECT EXISTS (
    SELECT 1
    FROM player
    WHERE country = :countryCode
        AND is_private = false
)
SQL;

        return (bool) $this->database
            ->executeQuery($query, [
                'countryCode' => $countryCode->name,
            ])
            ->fetchOne();
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
