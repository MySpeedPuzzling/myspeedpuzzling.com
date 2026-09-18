<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Results\PlayerConnection;
use SpeedPuzzling\Web\Results\PlayerConnectionCounts;

/**
 * Both directions of the favorites link (player.favorite_players, a JSON array
 * of player ids): the players someone follows and the players following them.
 *
 * Private players sort last and by code, so the order never hints at a name
 * the caller is not shown.
 */
readonly final class GetPlayerConnections
{
    private const string ORDER_BY = <<<SQL
ORDER BY
    other.is_private,
    CASE WHEN other.is_private THEN NULL ELSE LOWER(other.name) END NULLS LAST,
    other.code
SQL;

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<PlayerConnection>
     * @throws PlayerNotFound
     */
    public function favoritesOf(string $playerId): array
    {
        $orderBy = self::ORDER_BY;

        $query = <<<SQL
SELECT
    other.id AS player_id,
    other.code AS player_code,
    other.name AS player_name,
    other.country AS player_country,
    other.avatar AS player_avatar,
    other.is_private,
    other.favorite_players::jsonb @> jsonb_build_array(:playerId::text) AS is_mutual
FROM player me
CROSS JOIN LATERAL json_array_elements_text(me.favorite_players) AS favorite(player_id)
JOIN player other ON other.id = favorite.player_id::uuid
WHERE me.id = :playerId
{$orderBy}
SQL;

        return $this->fetch($query, $playerId);
    }

    /**
     * @return list<PlayerConnection>
     * @throws PlayerNotFound
     */
    public function followersOf(string $playerId): array
    {
        $orderBy = self::ORDER_BY;

        $query = <<<SQL
SELECT
    other.id AS player_id,
    other.code AS player_code,
    other.name AS player_name,
    other.country AS player_country,
    other.avatar AS player_avatar,
    other.is_private,
    me.favorite_players::jsonb @> jsonb_build_array(other.id::text) AS is_mutual
FROM player me
JOIN player other ON other.favorite_players::jsonb @> jsonb_build_array(:playerId::text)
WHERE me.id = :playerId
{$orderBy}
SQL;

        return $this->fetch($query, $playerId);
    }

    /**
     * @throws PlayerNotFound
     */
    public function countsOf(string $playerId): PlayerConnectionCounts
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $query = <<<SQL
SELECT
    json_array_length(me.favorite_players) AS favorites,
    (
        SELECT COUNT(*)
        FROM player other
        WHERE other.favorite_players::jsonb @> jsonb_build_array(:playerId::text)
    ) AS followers
FROM player me
WHERE me.id = :playerId
SQL;

        /** @var false|array{favorites: int, followers: int} $row */
        $row = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchAssociative();

        if ($row === false) {
            throw new PlayerNotFound();
        }

        return new PlayerConnectionCounts(
            favorites: $row['favorites'],
            followers: $row['followers'],
        );
    }

    /**
     * @return list<PlayerConnection>
     * @throws PlayerNotFound
     */
    private function fetch(string $query, string $playerId): array
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): PlayerConnection {
            /**
             * @var array{
             *     player_id: string,
             *     player_code: string,
             *     player_name: null|string,
             *     player_country: null|string,
             *     player_avatar: null|string,
             *     is_private: bool,
             *     is_mutual: bool,
             * } $row
             */

            return PlayerConnection::fromDatabaseRow($row);
        }, $data);
    }
}
