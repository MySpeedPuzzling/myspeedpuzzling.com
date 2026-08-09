<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\WjpfSyncCandidate;

readonly final class GetPlayersForWjpfSync
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Players eligible for a WJPF lookup, oldest registration first.
     *
     * Private profiles are excluded by decision: pairing discloses the address to a third
     * party, and a player who hid their profile has not asked for that.
     *
     * @param null|int $limit Null for every candidate.
     * @param bool $includeAlreadyChecked Re-check players that already have a mapping row.
     *
     * @return list<WjpfSyncCandidate>
     */
    public function all(null|int $limit = null, bool $includeAlreadyChecked = false): array
    {
        $query = <<<SQL
SELECT
    player.id AS player_id,
    LOWER(TRIM(player.email)) AS email,
    player.name AS player_name
FROM player
LEFT JOIN wjpf_identity ON wjpf_identity.player_id = player.id
WHERE player.email IS NOT NULL
    AND TRIM(player.email) != ''
    AND player.is_private = false
SQL;

        if ($includeAlreadyChecked === false) {
            $query .= "\n    AND wjpf_identity.id IS NULL";
        }

        $query .= "\nORDER BY player.registered_at ASC";

        $parameters = [];

        if ($limit !== null) {
            $query .= "\nLIMIT :limit";
            $parameters['limit'] = $limit;
        }

        /** @var array<array{player_id: string, email: string, player_name: null|string}> $rows */
        $rows = $this->database->executeQuery($query, $parameters)->fetchAllAssociative();

        return array_values(array_map(
            static fn (array $row): WjpfSyncCandidate => new WjpfSyncCandidate(
                playerId: $row['player_id'],
                email: $row['email'],
                playerName: $row['player_name'],
            ),
            $rows,
        ));
    }
}
