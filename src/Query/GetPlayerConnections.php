<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Results\PlayerConnection;
use SpeedPuzzling\Web\Results\PlayerConnectionCounts;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;

/**
 * Both directions of the favorites link (player.favorite_players, a JSON array
 * of player ids): the players someone follows and the players following them.
 *
 * Private players sort last and by code, so the order never hints at a name
 * the caller is not shown.
 */
readonly final class GetPlayerConnections
{
    public function __construct(
        private Connection $database,
        private PrivateProfileAccess $privateProfileAccess,
        private HiddenPlayers $hiddenPlayers,
    ) {
    }

    /**
     * @return list<PlayerConnection>
     * @throws PlayerNotFound
     */
    public function favoritesOf(string $playerId): array
    {
        $orderBy = $this->orderBy();
        $notHidden = $this->hiddenPlayers->sqlExclude('other.id');

        $query = <<<SQL
SELECT
    other.id AS player_id,
    other.code AS player_code,
    other.name AS player_name,
    other.country AS player_country,
    other.avatar AS player_avatar,
    {$this->privateProfileAccess->sqlIsPrivate('other')} AS is_private,
    other.is_private AS is_private_profile,
    other.favorite_players::jsonb @> jsonb_build_array(:playerId::text) AS is_mutual
FROM player me
CROSS JOIN LATERAL json_array_elements_text(me.favorite_players) AS favorite(player_id)
JOIN player other ON other.id = favorite.player_id::uuid
WHERE me.id = :playerId
    {$notHidden}
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
        $orderBy = $this->orderBy();
        $notHidden = $this->hiddenPlayers->sqlExclude('other.id');

        $query = <<<SQL
SELECT
    other.id AS player_id,
    other.code AS player_code,
    other.name AS player_name,
    other.country AS player_country,
    other.avatar AS player_avatar,
    {$this->privateProfileAccess->sqlIsPrivate('other')} AS is_private,
    other.is_private AS is_private_profile,
    me.favorite_players::jsonb @> jsonb_build_array(other.id::text) AS is_mutual
FROM player me
JOIN player other ON other.favorite_players::jsonb @> jsonb_build_array(:playerId::text)
WHERE me.id = :playerId
    {$notHidden}
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

        $notHidden = $this->hiddenPlayers->sqlExclude('other.id');
        $favorites = 'json_array_length(me.favorite_players)';

        if ($notHidden !== '') {
            $favoriteNotHidden = $this->hiddenPlayers->sqlExclude('favorite.player_id::uuid');
            $favorites = "(SELECT COUNT(*) FROM json_array_elements_text(me.favorite_players) AS favorite(player_id) WHERE true{$favoriteNotHidden})";
        }

        $query = <<<SQL
SELECT
    {$favorites} AS favorites,
    (
        SELECT COUNT(*)
        FROM player other
        WHERE other.favorite_players::jsonb @> jsonb_build_array(:playerId::text)
            {$notHidden}
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
             *     is_private_profile: bool,
             *     is_mutual: bool,
             * } $row
             */

            return PlayerConnection::fromDatabaseRow($row);
        }, $data);
    }

    private function orderBy(): string
    {
        $isPrivate = $this->privateProfileAccess->sqlIsPrivate('other');

        return <<<SQL
ORDER BY
    {$isPrivate},
    CASE WHEN {$isPrivate} THEN NULL ELSE LOWER(other.name) END NULLS LAST,
    other.code
SQL;
    }
}
