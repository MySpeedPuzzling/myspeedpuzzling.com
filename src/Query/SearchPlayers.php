<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\PlayerIdentification;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;

readonly final class SearchPlayers
{
    public function __construct(
        private Connection $database,
        private HiddenPlayers $hiddenPlayers,
        private PrivateProfileAccess $privateProfileAccess,
    ) {
    }

    /**
     * Organiser and admin tooling passes includeHidden: blocking someone must not make them
     * unassignable at an event the blocker runs.
     *
     * @return list<PlayerIdentification>
     */
    public function fulltext(string $search, null|int $limit = null, bool $includeHidden = false): array
    {
        $notHidden = $includeHidden ? '' : $this->hiddenPlayers->sqlExclude('player.id');

        // A private player is found by their exact player code only - the handle they hand out
        // themselves, e.g. to be added to a pair/team time - and then without name, avatar or
        // country. Players on their allow list find them like anybody else.
        $isPrivate = $this->privateProfileAccess->sqlIsPrivate('player');

        $query = <<<SQL
SELECT
    id AS player_id,
    CASE WHEN {$isPrivate} THEN NULL ELSE name END AS player_name,
    CASE WHEN {$isPrivate} THEN NULL ELSE country END AS player_country,
    code AS player_code,
    CASE WHEN {$isPrivate} THEN NULL ELSE avatar END AS player_avatar,
    (
      CASE
        WHEN LOWER(code) = LOWER(:searchQuery) THEN 6 -- Exact match on code with diacritics
        WHEN LOWER(unaccent(code)) = LOWER(unaccent(:searchQuery)) THEN 4 -- Exact match on code without diacritics
        WHEN LOWER(code) LIKE LOWER(:searchEndLikeQuery) OR LOWER(code) LIKE LOWER(:searchStartLikeQuery) THEN 4 -- Code starting/ending with search query (diacritics)
        WHEN LOWER(unaccent(code)) LIKE LOWER(unaccent(:searchEndLikeQuery)) OR LOWER(unaccent(code)) LIKE LOWER(unaccent(:searchStartLikeQuery)) THEN 3 -- Code starting/ending with search query without diacritics
        WHEN LOWER(code) LIKE LOWER(:searchFullLikeQuery) THEN 3 -- Partial match on code with search query (diacritics)
        WHEN LOWER(unaccent(code)) LIKE LOWER(unaccent(:searchFullLikeQuery)) THEN 2 -- Partial match on code with search query without diacritics
        ELSE 0
      END
      +
      CASE
        WHEN LOWER(name) = LOWER(:searchQuery) THEN 5 -- Exact match on name with diacritics
        WHEN LOWER(unaccent(name)) = LOWER(unaccent(:searchQuery)) THEN 3 -- Exact match on name without diacritics
        WHEN LOWER(name) LIKE LOWER(:searchEndLikeQuery) OR LOWER(name) LIKE LOWER(:searchStartLikeQuery) THEN 3 -- Name starting/ending with search query (diacritics)
        WHEN LOWER(unaccent(name)) LIKE LOWER(unaccent(:searchEndLikeQuery)) OR LOWER(unaccent(name)) LIKE LOWER(unaccent(:searchStartLikeQuery)) THEN 2 -- Name starting/ending with search query without diacritics
        WHEN LOWER(name) LIKE LOWER(:searchFullLikeQuery) THEN 2 -- Partial match on name with search query (diacritics)
        WHEN LOWER(unaccent(name)) LIKE LOWER(unaccent(:searchFullLikeQuery)) THEN 1 -- Partial match on name with search query without diacritics
        ELSE 0
      END
    ) AS match_score
FROM player
WHERE (
    LOWER(name) LIKE LOWER(:searchFullLikeQuery) OR LOWER(code) LIKE LOWER(:searchFullLikeQuery)
    OR LOWER(unaccent(name)) LIKE LOWER(unaccent(:searchFullLikeQuery)) OR LOWER(unaccent(code)) LIKE LOWER(unaccent(:searchFullLikeQuery))
)
    AND ({$isPrivate} = false OR LOWER(code) = LOWER(:searchQuery))
    {$notHidden}
ORDER BY match_score DESC
SQL;

        if ($limit !== null) {
            $query .= ' LIMIT ' . $limit;
        }

        $data = $this->database
            ->executeQuery($query, [
                'searchQuery' => $search,
                'searchStartLikeQuery' => "%$search",
                'searchEndLikeQuery' => "$search%",
                'searchFullLikeQuery' => "%$search%",
                'limit' => $limit,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): PlayerIdentification {
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
