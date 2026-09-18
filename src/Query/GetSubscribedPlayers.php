<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
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
     * Players who have at least one of the given players in their favorites - each of them once,
     * however many of the given players they follow.
     *
     * @param array<string> $playerIds
     *
     * @throws PlayerNotFound
     *
     * @return list<string>
     */
    public function ofPlayers(array $playerIds): array
    {
        foreach ($playerIds as $playerId) {
            if (Uuid::isValid($playerId) === false) {
                throw new PlayerNotFound();
            }
        }

        if ($playerIds === []) {
            return [];
        }

        $query = <<<SQL
SELECT DISTINCT p.id
FROM player p
JOIN LATERAL json_array_elements_text(p.favorite_players) as fav(uuid)
ON fav.uuid IN (:playerIds)
SQL;

        /**
         * @var list<string> $rows
         */
        $rows = $this->database
            ->executeQuery($query, [
                'playerIds' => array_values($playerIds),
            ], [
                'playerIds' => ArrayParameterType::STRING,
            ])
            ->fetchFirstColumn();

        return $rows;
    }
}
