<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;

readonly final class GetSubscribedPlayers
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @throws PlayerNotFound
     *
     * @return array<string>
     */
    public function ofPlayer(string $playerId): array
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $query = <<<SQL
SELECT p.id
FROM player p
JOIN LATERAL json_array_elements_text(p.favorite_players) as fav(uuid)
ON fav.uuid = :playerId;
SQL;

        /**
         * @var array<string> $rows
         */
        $rows = $this->database
            ->executeQuery($query, [
                'playerId' => $playerId,
            ])
            ->fetchFirstColumn();

        return $rows;
    }
}
