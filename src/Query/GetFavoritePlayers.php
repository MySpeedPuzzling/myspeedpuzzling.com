<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Results\MostFavoritePlayer;
use SpeedPuzzling\Web\Results\PlayerIdentification;
use SpeedPuzzling\Web\Services\HiddenPlayers;
use SpeedPuzzling\Web\Services\PrivateProfileAccess;

readonly final class GetFavoritePlayers
{
    public function __construct(
        private Connection $database,
        private HiddenPlayers $hiddenPlayers,
        private PrivateProfileAccess $privateProfileAccess,
    ) {
    }

    /**
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
    CASE WHEN {$isPrivate} THEN NULL ELSE fav.country END AS player_country
FROM player
CROSS JOIN LATERAL json_array_elements_text(player.favorite_players::json) AS fav_player_id
JOIN player fav ON fav.id = fav_player_id::uuid
WHERE player.id = :playerId{$notHidden};
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
             * } $row
             */

            return PlayerIdentification::fromDatabaseRow($row);
        }, $data);
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
             *     favorite_count: int,
             * } $row
             */

            return MostFavoritePlayer::fromDatabaseRow($row);
        }, $data);
    }
}
