<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Doctrine;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Tools\DsnParser;
use SpeedPuzzling\Web\Exceptions\DatabaseNotReady;
use Symfony\Component\Clock\ClockInterface;

/**
 * Waits until the database answers a query - used by bin/wait-for-database
 * before a container migrates on boot.
 *
 * An open port is not enough: while Postgres replays WAL after an unclean stop it
 * accepts TCP and turns every login away ("the database system is starting up").
 * After the host's hard reset on 2026-09-03 that lasted 14 s, and both web
 * containers - past wait-for-it in 0 s - crash-looped through four failed
 * migrations each before the database let them in.
 *
 * Deliberately outside the Symfony kernel: a failed console command logs a
 * critical error, so a probe through bin/console would page once per attempt.
 */
final readonly class DatabaseReadiness
{
    private const array SCHEME_DRIVERS = [
        'postgres' => 'pdo_pgsql',
        'postgresql' => 'pdo_pgsql',
        'pgsql' => 'pdo_pgsql',
    ];

    private const int CONNECT_TIMEOUT_SECONDS = 3;

    public function __construct(
        private ClockInterface $clock,
        private float $retryIntervalSeconds = 1.0,
    ) {
    }

    /**
     * @return int how many attempts it took
     * @throws DatabaseNotReady when the database did not answer within $timeoutSeconds
     */
    public function waitFor(string $databaseUrl, float $timeoutSeconds): int
    {
        $params = (new DsnParser(self::SCHEME_DRIVERS))->parse($databaseUrl);
        // libpq waits forever on a host that drops packets
        $params['driverOptions'] = [\PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT_SECONDS];

        $deadline = $this->now() + $timeoutSeconds;
        $attempts = 0;

        while (true) {
            $attempts++;
            $connection = DriverManager::getConnection($params);

            try {
                $connection->executeQuery('SELECT 1');

                return $attempts;
            } catch (DbalException $exception) {
                if ($this->now() + $this->retryIntervalSeconds > $deadline) {
                    throw new DatabaseNotReady($attempts, $exception);
                }
            } finally {
                $connection->close();
            }

            $this->clock->sleep($this->retryIntervalSeconds);
        }
    }

    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
