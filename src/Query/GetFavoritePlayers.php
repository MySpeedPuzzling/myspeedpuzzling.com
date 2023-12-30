<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Results\FavoritePlayer;

readonly final class GetFavoritePlayers
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @return array<FavoritePlayer>
     */
    public function forPlayerId(string $playerId): array
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $query = <<<SQL
SELECT
    fav.id AS player_id,
    fav.name AS player_name,
    fav.code AS player_code
FROM player
CROSS JOIN LATERAL json_array_elements_text(player.favorite_players::json) AS fav_player_id
JOIN player fav ON fav.id = fav_player_id::uuid
WHERE player.id = :playerId;
SQL;

        $data = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchAllAssociative();

        return array_map(static function(array $row): FavoritePlayer {
            /**
             * @var array{
             *     player_id: string,
             *     player_code: string,
             *     player_name: null|string,
             * } $row
             */

            return FavoritePlayer::fromDatabaseRow($row);
        }, $data);
    }
}
