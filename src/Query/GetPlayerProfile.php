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
    code
FROM player
WHERE player.id = :playerId
SQL;

        /**
         * @var null|array{
         *     player_id: string,
         *     user_id: null|string,
         *     player_name: null|string,
         *     email: null|string,
         *     country: null|string,
         *     city: null|string,
         *     code: string,
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
    code
FROM player
WHERE player.user_id = :userId
SQL;

        /**
         * @var null|array{
         *     player_id: string,
         *     user_id: null|string,
         *     player_name: null|string,
         *     email: null|string,
         *     country: null|string,
         *     city: null|string,
         *     code: string,
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
