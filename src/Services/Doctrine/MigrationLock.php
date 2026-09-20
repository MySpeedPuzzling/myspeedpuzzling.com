<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Doctrine;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Tools\DsnParser;

/**
 * Lets only one container at a time run the boot migrations.
 *
 * A blue-green rollout starts two `web` containers in the same second and both migrate on boot. The
 * loser of that race failed on the winner's half-created objects (2026-09-20: CRITICAL "duplicate key …
 * pg_type_typname_nsp_index") - harmless while every migration is transactional, a half-applied schema
 * the day one is not. A session-level Postgres advisory lock makes the second container wait, after
 * which it finds nothing left to migrate.
 *
 * Fails open: a lock that cannot be had in time must not keep the site from starting - the work then
 * runs unguarded, exactly as it did before this class existed.
 */
final readonly class MigrationLock
{
    // Arbitrary, but stable: every container must ask for the same lock
    public const int LOCK_KEY = 20260920;

    private const array SCHEME_DRIVERS = [
        'postgres' => 'pdo_pgsql',
        'postgresql' => 'pdo_pgsql',
        'pgsql' => 'pdo_pgsql',
    ];

    /**
     * @template T
     * @param callable(bool $locked): T $work Receives whether the lock is held
     * @return T
     */
    public function runExclusively(string $databaseUrl, float $timeoutSeconds, callable $work): mixed
    {
        $connection = DriverManager::getConnection((new DsnParser(self::SCHEME_DRIVERS))->parse($databaseUrl));
        $locked = false;

        try {
            // Waiting for an advisory lock honours lock_timeout like any other lock wait
            $connection->executeStatement(sprintf('SET lock_timeout = %d', (int) round($timeoutSeconds * 1000)));
            $connection->executeQuery('SELECT pg_advisory_lock(:key)', ['key' => self::LOCK_KEY]);
            $locked = true;
        } catch (DbalException) {
            // Timed out, or the database refused - run unguarded rather than not at all
        }

        try {
            return $work($locked);
        } finally {
            if ($locked) {
                try {
                    $connection->executeQuery('SELECT pg_advisory_unlock(:key)', ['key' => self::LOCK_KEY]);
                } catch (DbalException) {
                    // Closing the session below releases the lock anyway
                }
            }

            $connection->close();
        }
    }
}
