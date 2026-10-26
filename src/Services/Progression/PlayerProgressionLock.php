<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Progression;

use Doctrine\DBAL\Connection;

/**
 * Serializes every write of a player's progression - the XP ledger with player.xp_total / level, the achievements
 * with player.achievement_points - by a lock per player held until the transaction ends (the messenger
 * doctrine_transaction middleware commits). Two consumers working on the same player (an award next to a rebuild,
 * two quick saves of one week, the settlement cron) otherwise both read the same total and one update is lost, or
 * both hand the same weekly slot out.
 *
 * Take it BEFORE the player is loaded or the ledger is read: the totals read after it are the committed ones.
 * Several players are locked in ascending key order, so two transactions never wait for each other in a circle.
 * Within a transaction it is re-entrant (an advisory lock the transaction already holds is granted again).
 *
 * A transaction-scoped advisory lock, not a lock of the player row: the row lock (SELECT … FOR UPDATE) also made every
 * insert referencing the player wait - the foreign key check takes FOR KEY SHARE on the row - so a save, a stopwatch,
 * a notification of a locked player waited until the locking transaction committed (10 s saves behind the settlement
 * cron, 2026-10-26). The advisory lock is only ever waited for by another PlayerProgressionLock; nothing else touches
 * it. Transaction-scoped, never session-scoped: the commit or rollback releases it, so a long-lived worker connection
 * cannot keep it.
 *
 * Key = (KEY_SPACE, CRC32 of the player id): the two-integer form is a key space of its own in Postgres, apart from the
 * single-bigint locks (IdLock, MigrationLock, the Symfony lock store). Two players sharing a CRC32 only wait for each
 * other needlessly - never a missed lock.
 */
readonly class PlayerProgressionLock
{
    public const int KEY_SPACE = 0x58500001;

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @param list<string> $playerIds
     */
    public function lock(array $playerIds): void
    {
        $keys = array_values(array_unique(array_map(self::keyOf(...), $playerIds)));
        sort($keys);

        foreach ($keys as $key) {
            $this->database->executeQuery(
                'SELECT pg_advisory_xact_lock(CAST(:keySpace AS INTEGER), CAST(:key AS INTEGER))',
                ['keySpace' => self::KEY_SPACE, 'key' => $key],
            );
        }
    }

    /**
     * The player's lock key: CRC32 of the id as a signed 32-bit integer (what pg_advisory_xact_lock(int, int) takes)
     */
    public static function keyOf(string $playerId): int
    {
        $crc = crc32(strtolower($playerId));

        return $crc > 0x7FFFFFFF ? $crc - 0x100000000 : $crc;
    }
}
