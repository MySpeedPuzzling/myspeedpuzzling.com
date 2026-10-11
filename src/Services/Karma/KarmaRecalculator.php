<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Karma;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

/**
 * Stores the derived karma totals on player.karma (docs/features/karma.md) - the column the public list, the profile
 * chip and the review queues read. Karma has no ledger: this is a cache of KarmaDerivation, written by one set-based
 * UPDATE … FROM (aggregate). A documented bulk operation (CLAUDE.md): loading every player to set one derived number
 * would cost thousands of statements for what one statement does, and nothing else writes the column.
 *
 * Idempotent: a run only touches rows whose stored total differs, so running it twice changes nothing.
 */
readonly final class KarmaRecalculator
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Every player - the backfill and the daily safety net.
     *
     * @return int How many players' karma changed
     */
    public function recalculateAll(): int
    {
        $items = KarmaDerivation::itemsCte(scoped: false);
        $eligible = KarmaDerivation::ELIGIBLE_PLAYER;

        $sql = <<<SQL
{$items},
karma_totals AS (
    SELECT i.player_id, COUNT(*) FILTER (WHERE i.counted) AS karma
    FROM karma_items i
    JOIN player pl ON {$eligible}
    GROUP BY i.player_id
)
UPDATE player
SET karma = fresh.karma
FROM (
    SELECT p.id, COALESCE(t.karma, 0) AS karma
    FROM player p
    LEFT JOIN karma_totals t ON t.player_id = p.id
) fresh
WHERE player.id = fresh.id
  AND player.karma IS DISTINCT FROM fresh.karma
SQL;

        return (int) $this->database->executeStatement($sql);
    }

    /**
     * The people one decision concerns (the proposer, the moderator, the adders of the puzzles involved).
     *
     * @param list<string> $playerIds
     * @return int How many players' karma changed
     */
    public function recalculateFor(array $playerIds): int
    {
        $playerIds = array_values(array_unique(array_filter(
            array_map(strtolower(...), $playerIds),
            static fn (string $id): bool => Uuid::isValid($id),
        )));

        if ($playerIds === []) {
            return 0;
        }

        $items = KarmaDerivation::itemsCte(scoped: true);
        $eligible = KarmaDerivation::ELIGIBLE_PLAYER;

        $sql = <<<SQL
{$items},
karma_totals AS (
    SELECT i.player_id, COUNT(*) FILTER (WHERE i.counted) AS karma
    FROM karma_items i
    JOIN player pl ON {$eligible}
    GROUP BY i.player_id
)
UPDATE player
SET karma = fresh.karma
FROM (
    SELECT p.id, COALESCE(t.karma, 0) AS karma
    FROM player p
    LEFT JOIN karma_totals t ON t.player_id = p.id
    WHERE p.id IN (:karmaPlayerIds)
) fresh
WHERE player.id = fresh.id
  AND player.karma IS DISTINCT FROM fresh.karma
SQL;

        return (int) $this->database->executeStatement(
            $sql,
            ['karmaPlayerIds' => $playerIds],
            ['karmaPlayerIds' => ArrayParameterType::STRING],
        );
    }
}
