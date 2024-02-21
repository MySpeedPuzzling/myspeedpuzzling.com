<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\PlayerIdentification;

readonly final class SearchPlayers
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<PlayerIdentification>
     */
    public function fulltext(string $search): array
    {
        $query = <<<SQL
SELECT
    id AS player_id,
    name AS player_name,
    country AS player_country,
    code AS player_code,
    CASE
        WHEN LOWER(name) = LOWER(:searchQuery) OR LOWER(code) = LOWER(:searchQuery) THEN 5 -- Exact match with diacritics
        WHEN LOWER(unaccent(name)) = LOWER(unaccent(:searchQuery)) OR LOWER(unaccent(code)) = LOWER(unaccent(:searchQuery)) THEN 4 -- Exact match without diacritics
        WHEN LOWER(name) LIKE LOWER(:searchEndLikeQuery) OR LOWER(name) LIKE LOWER(:searchStartLikeQuery) OR LOWER(code) LIKE LOWER(:searchEndLikeQuery) OR LOWER(code) LIKE LOWER(:searchStartLikeQuery) THEN 3 -- Starts or ends with the query with diacritics
        WHEN LOWER(unaccent(name)) LIKE LOWER(unaccent(:searchEndLikeQuery)) OR LOWER(unaccent(name)) LIKE LOWER(unaccent(:searchStartLikeQuery)) OR LOWER(unaccent(code)) LIKE LOWER(unaccent(:searchEndLikeQuery)) OR LOWER(unaccent(code)) LIKE LOWER(unaccent(:searchStartLikeQuery)) THEN 2 -- Starts or ends with the query without diacritics
        WHEN LOWER(name) LIKE LOWER(:searchFullLikeQuery) OR LOWER(code) LIKE LOWER(:searchFullLikeQuery) THEN 1 -- Partial match with diacritics
        ELSE 0 -- Partial match without diacritics or any other case
    END as match_score
FROM player
WHERE LOWER(name) LIKE LOWER(:searchFullLikeQuery) OR LOWER(code) LIKE LOWER(:searchFullLikeQuery)
   OR LOWER(unaccent(name)) LIKE LOWER(unaccent(:searchFullLikeQuery)) OR LOWER(unaccent(code)) LIKE LOWER(unaccent(:searchFullLikeQuery))
   OR LOWER(name) = LOWER(:searchQuery) OR LOWER(code) = LOWER(:searchQuery)
   OR LOWER(unaccent(name)) = LOWER(unaccent(:searchQuery)) OR LOWER(unaccent(code)) = LOWER(unaccent(:searchQuery))
   OR LOWER(name) LIKE LOWER(:searchEndLikeQuery) OR LOWER(name) LIKE LOWER(:searchStartLikeQuery) OR LOWER(code) LIKE LOWER(:searchEndLikeQuery) OR LOWER(code) LIKE LOWER(:searchStartLikeQuery)
   OR LOWER(unaccent(name)) LIKE LOWER(unaccent(:searchEndLikeQuery)) OR LOWER(unaccent(name)) LIKE LOWER(unaccent(:searchStartLikeQuery)) OR LOWER(unaccent(code)) LIKE LOWER(unaccent(:searchEndLikeQuery)) OR LOWER(unaccent(code)) LIKE LOWER(unaccent(:searchStartLikeQuery))
ORDER BY match_score DESC
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'searchQuery' => $search,
                'searchStartLikeQuery' => "%$search",
                'searchEndLikeQuery' => "$search%",
                'searchFullLikeQuery' => "%$search%",
            ])
            ->fetchAllAssociative();

        return array_map(static function(array $row): PlayerIdentification {
            /**
             * @var array{
             *     player_id: string,
             *     player_name: null|string,
             *     player_country: null|string,
             *     player_code: string,
             * } $row
             */

            return PlayerIdentification::fromDatabaseRow($row);
        }, $data);
    }
}
