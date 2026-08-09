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
     * @param bool $includeAlreadyChecked Re-check players that already have a row. Players we
     *                                    already hold a WJPF id for stay excluded unless
     *                                    $includePaired is also set - a repeat run is normally
     *                                    about catching people who have joined WJPF since, and
     *                                    re-asking about thousands of settled mappings is pure
     *                                    load on their server.
     * @param bool $includePaired Also re-check players already mapped to a WJPF id.
     *
     * @return list<WjpfSyncCandidate>
     */
    public function all(
        null|int $limit = null,
        bool $includeAlreadyChecked = false,
        bool $includePaired = false,
    ): array {
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
        } elseif ($includePaired === false) {
            // "Already paired" means we hold their IdJugador, whatever the latest status says -
            // a player who was matched once and has since gone not_found still has a mapping
            // worth keeping, and asking about them again cannot improve on it.
            $query .= "\n    AND wjpf_identity.wjpf_id IS NULL";
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
