<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

/**
 * Writes a chunk of monthly snapshots (player_insights_snapshot) in one statement. Native SQL on purpose - a genuine
 * bulk operation: one row per active player and month, upserted (a re-run of the month replaces its rows), no entity
 * is ever loaded one by one for it. Called by StorePlayerInsightsSnapshotsHandler, inside its transaction.
 */
readonly final class PlayerInsightsSnapshotWriter
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param array<string, array<string, mixed>> $snapshots player id => snapshot data
     */
    public function write(DateTimeImmutable $month, array $snapshots, DateTimeImmutable $now): int
    {
        if ($snapshots === []) {
            return 0;
        }

        $values = [];
        $parameters = ['month' => $month->format('Y-m-d'), 'now' => $now->format('Y-m-d H:i:s')];
        $index = 0;

        foreach ($snapshots as $playerId => $data) {
            $values[] = "(CAST(:id{$index} AS uuid), CAST(:player{$index} AS uuid), CAST(:month AS date), CAST(:data{$index} AS jsonb), CAST(:now AS timestamp))";
            $parameters['id' . $index] = Uuid::uuid7()->toString();
            $parameters['player' . $index] = $playerId;
            $parameters['data' . $index] = json_encode($data, JSON_THROW_ON_ERROR);
            $index++;
        }

        $sql = 'INSERT INTO player_insights_snapshot (id, player_id, month, data, computed_at) VALUES '
            . implode(', ', $values)
            . ' ON CONFLICT (player_id, month) DO UPDATE SET data = EXCLUDED.data, computed_at = EXCLUDED.computed_at';

        return (int) $this->connection->executeStatement($sql, $parameters);
    }
}
