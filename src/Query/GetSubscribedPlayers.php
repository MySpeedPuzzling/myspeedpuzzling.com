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

        // jsonb ?| = "has any of these strings as an array element", served by the GIN index
        // custom_player_favorite_players_gin (Version20260918171659). One row per player by
        // construction, so no DISTINCT. Written ??| because a lone ? is a PDO placeholder:
        // DBAL leaves ?? alone and PDO sends it to PostgreSQL as a literal ?. The function
        // form jsonb_exists_any() is equivalent but the planner never uses the index for it.
        $query = <<<SQL
SELECT p.id
FROM player p
WHERE p.favorite_players::jsonb ??| ARRAY[:playerIds]::text[]
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
