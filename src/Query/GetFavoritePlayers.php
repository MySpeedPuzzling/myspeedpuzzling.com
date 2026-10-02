<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Results\MostFavoritePlayer;
use SpeedPuzzling\Web\Results\PlayerFollowers;
use SpeedPuzzling\Web\Results\PlayerIdentification;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;
use SpeedPuzzling\Web\Value\CountryCode;

readonly final class GetFavoritePlayers
{
    public function __construct(
        private Connection $database,
        private HiddenPlayers $hiddenPlayers,
        private PrivateProfileAccess $privateProfileAccess,
    ) {
    }

    /**
     * By name; private players the viewer may not see come last, by code - the order never hints at a name the viewer
     * is not shown (the convention of GetPlayerConnections).
     *
     * @return array<PlayerIdentification>
     * @throws PlayerNotFound
     */
    public function forPlayerId(string $playerId): array
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $notHidden = $this->hiddenPlayers->sqlExclude('fav.id');
        // A followed private player stays in the list, by code only - unless they let the viewer in
        $isPrivate = $this->privateProfileAccess->sqlIsPrivate('fav');

        $query = <<<SQL
SELECT
    fav.id AS player_id,
    CASE WHEN {$isPrivate} THEN NULL ELSE fav.name END AS player_name,
    fav.code AS player_code,
    CASE WHEN {$isPrivate} THEN NULL ELSE fav.country END AS player_country,
    CASE WHEN {$isPrivate} THEN NULL ELSE fav.avatar END AS player_avatar,
    {$isPrivate} AS is_private
FROM player
CROSS JOIN LATERAL json_array_elements_text(player.favorite_players::json) AS fav_player_id
JOIN player fav ON fav.id = fav_player_id::uuid
WHERE player.id = :playerId{$notHidden}
ORDER BY
    {$isPrivate},
    CASE WHEN {$isPrivate} THEN NULL ELSE LOWER(fav.name) END NULLS LAST,
    fav.code;
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
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
             *     is_private: bool,
             * } $row
             */

            return PlayerIdentification::fromDatabaseRow($row);
        }, $data);
    }

    /**
     * The players who have `$playerId` in their favorites - shown to that player only (the favorite puzzlers page is
     * personal). They never chose to be listed, so a player the viewer hides (blocklist) or who blocked the viewer is
     * left out completely, and a
     * private profile the viewer may not see is only counted: the statement returns no id, code or name for it, and its
     * place in the order hints at nothing. (API v1 /me/followers lists them masked, by code - GetPlayerConnections.)
     *
     * Served by custom_player_favorite_players_gin (docs/database-indexes.md).
     *
     * @throws PlayerNotFound
     */
    public function followersOf(string $playerId): PlayerFollowers
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $notHidden = $this->hiddenPlayers->sqlExclude('follower.id');
        $isPrivate = $this->privateProfileAccess->sqlIsPrivate('follower');

        $query = <<<SQL
SELECT
    CASE WHEN followers.hidden THEN NULL ELSE followers.id END AS player_id,
    CASE WHEN followers.hidden THEN NULL ELSE followers.code END AS player_code,
    CASE WHEN followers.hidden THEN NULL ELSE followers.name END AS player_name,
    CASE WHEN followers.hidden THEN NULL ELSE followers.country END AS player_country,
    CASE WHEN followers.hidden THEN NULL ELSE followers.avatar END AS player_avatar
FROM (
    SELECT follower.id, follower.code, follower.name, follower.country, follower.avatar, {$isPrivate} AS hidden
    FROM player follower
    WHERE follower.favorite_players::jsonb @> jsonb_build_array(:playerId::text)
        AND follower.id <> :playerId{$notHidden}
        -- Nor a player who blocked the viewer (an older block, or an admin block protecting them): they must not
        -- show up to the person they keep away, and their absence tells nothing
        AND NOT EXISTS (
            SELECT 1 FROM user_block ub WHERE ub.blocker_id = follower.id AND ub.blocked_id = :playerId
        )
) AS followers
ORDER BY
    followers.hidden,
    CASE WHEN followers.hidden THEN NULL ELSE LOWER(followers.name) END NULLS LAST,
    CASE WHEN followers.hidden THEN NULL ELSE followers.code END
SQL;

        $rows = $this->database
            ->executeQuery($query, [
                'playerId' => strtolower($playerId),
            ])
            ->fetchAllAssociative();

        $players = [];
        $privateCount = 0;

        foreach ($rows as $row) {
            /**
             * @var array{
             *     player_id: null|string,
             *     player_code: null|string,
             *     player_name: null|string,
             *     player_country: null|string,
             *     player_avatar: null|string,
             * } $row
             */
            $followerId = $row['player_id'];
            $followerCode = $row['player_code'];

            if ($followerId === null || $followerCode === null) {
                $privateCount++;

                continue;
            }

            $players[] = new PlayerIdentification(
                playerId: $followerId,
                playerCode: $followerCode,
                playerName: $row['player_name'],
                playerCountry: CountryCode::fromCode($row['player_country']),
                playerAvatar: $row['player_avatar'],
            );
        }

        return new PlayerFollowers($players, $privateCount);
    }

    /**
     * @return array<MostFavoritePlayer>
     */
    public function mostFavorite(int $limit): array
    {
        $notHidden = $this->hiddenPlayers->sqlExclude('fav_player.id');

        $query = <<<SQL
SELECT 
    fav_player.id AS player_id, 
    fav_player.name AS player_name, 
    fav_player.code AS player_code, 
    fav_player.country AS player_country,
    fav_player.avatar AS player_avatar,
    COUNT(fav_player.id) AS favorite_count
FROM player
CROSS JOIN LATERAL JSON_ARRAY_ELEMENTS_TEXT(player.favorite_players) AS fav_player_id
JOIN player fav_player ON fav_player_id::uuid = fav_player.id AND fav_player.is_private = false{$notHidden}
GROUP BY fav_player.id, fav_player.name, fav_player.code
ORDER BY favorite_count DESC
LIMIT :limit
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'limit' => $limit,
            ])
            ->fetchAllAssociative();

        return array_map(static function (array $row): MostFavoritePlayer {
            /**
             * @var array{
             *     player_id: string,
             *     player_code: string,
             *     player_name: null|string,
             *     player_country: null|string,
             *     player_avatar: null|string,
             *     favorite_count: int,
             * } $row
             */

            return MostFavoritePlayer::fromDatabaseRow($row);
        }, $data);
    }
}
