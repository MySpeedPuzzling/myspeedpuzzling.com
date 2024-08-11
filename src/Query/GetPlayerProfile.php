<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Results\PlayerProfile;

readonly final class GetPlayerProfile
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function byId(string $playerId): PlayerProfile
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $query = <<<SQL
SELECT
    player.id AS player_id,
    name AS player_name,
    user_id,
    email,
    country,
    city,
    code,
    favorite_players,
    avatar,
    bio,
    facebook,
    instagram,
    wjpc_modal_displayed
FROM player
WHERE player.id = :playerId
SQL;

        /**
         * @var false|array{
         *     player_id: string,
         *     user_id: null|string,
         *     player_name: null|string,
         *     email: null|string,
         *     country: null|string,
         *     city: null|string,
         *     code: string,
         *     favorite_players: string,
         *     avatar: null|string,
         *     bio: null|string,
         *     facebook: null|string,
         *     instagram: null|string,
         *     wjpc_modal_displayed: bool,
         * } $row
         */
        $row = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchAssociative();

        if (is_array($row) === false) {
            throw new PlayerNotFound();
        }

        return PlayerProfile::fromDatabaseRow($row);
    }

    /**
     * @throws PlayerNotFound
     */
    public function byUserId(string $userId): PlayerProfile
    {
        $query = <<<SQL
SELECT
    player.id AS player_id,
    name AS player_name,
    user_id,
    email,
    country,
    city,
    code,
    favorite_players,
    avatar,
    bio,
    facebook,
    instagram,
    wjpc_modal_displayed
FROM player
WHERE player.user_id = :userId
SQL;

        /**
         * @var false|array{
         *     player_id: string,
         *     user_id: null|string,
         *     player_name: null|string,
         *     email: null|string,
         *     country: null|string,
         *     city: null|string,
         *     code: string,
         *     favorite_players: string,
         *     avatar: null|string,
         *     bio: null|string,
         *     facebook: null|string,
         *     instagram: null|string,
         *     wjpc_modal_displayed: bool,
         * } $row
         */
        $row = $this->database
            ->executeQuery($query, [
                'userId' => $userId,
            ])
            ->fetchAssociative();

        if (is_array($row) === false) {
            throw new PlayerNotFound();
        }

        return PlayerProfile::fromDatabaseRow($row);
    }
}
