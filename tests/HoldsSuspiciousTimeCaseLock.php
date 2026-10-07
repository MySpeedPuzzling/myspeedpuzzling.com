<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PDO;
use Throwable;

/**
 * docs/features/suspicious-time-review.md, "Moderator queue": the scan, an edit's re-check and the moderators'
 * decisions take a case's row lock before they read it. Another request holding it - not committed yet - is simulated
 * with a second database connection (it sees the fixtures, committed when the test database was built); this test's
 * statements give up after 200 ms instead of waiting for it.
 */
trait HoldsSuspiciousTimeCaseLock
{
    /**
     * @return PDO the other request - roll it back at the end
     */
    private function holdCaseInAnotherRequest(Connection $database, string $caseId): PDO
    {
        // The database of this test process (tests/bootstrap.php points every ParaTest worker at its own)
        $databaseUrl = $_ENV['DATABASE_URL'] ?? null;
        self::assertIsString($databaseUrl);
        $url = parse_url($databaseUrl);
        self::assertIsArray($url);

        $otherRequest = new PDO(
            sprintf('pgsql:host=%s;port=%d;dbname=%s', $url['host'] ?? 'postgres', $url['port'] ?? 5432, ltrim($url['path'] ?? '', '/')),
            $url['user'] ?? null,
            $url['pass'] ?? null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $otherRequest->beginTransaction();
        $otherRequest->query(sprintf("SELECT id FROM suspicious_time_case WHERE id = '%s' FOR UPDATE", $caseId));

        // Until the end of the test's transaction
        $database->executeStatement("SET LOCAL lock_timeout = '200ms'");

        return $otherRequest;
    }

    /**
     * The statement that ran into the held lock (lock_not_available), null when none did.
     */
    private static function statementThatWaited(Throwable $exception): null|string
    {
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof DriverException && $cause->getSQLState() === '55P03') {
                return $cause->getQuery()?->getSQL();
            }
        }

        return null;
    }
}
