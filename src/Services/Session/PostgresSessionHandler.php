<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Session;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\AbstractSessionHandler;
use Symfony\Contracts\Service\ResetInterface;

/**
 * PHP sessions in the `sessions` table, on the Doctrine connection the worker already holds.
 *
 * Replaces Symfony's PdoSessionHandler, which cost every signed-in request about 15 ms (production,
 * measured 2026-09-30) in two places:
 *
 *  - It is built from a DSN, so it opened and closed its own connection per request: 7.5 ms of
 *    SCRAM + TCP, plus 1-2 ms for the new backend's cold catalogue. Here the session queries run
 *    on the worker's existing connection instead - no connect, a warm backend, and DBAL's usual
 *    handling when that connection dies (it is closed and reopened on the next request).
 *  - Nine requests in ten wrote the row back, and each write waited for the WAL flush (commit
 *    p50 4.6 ms, mean 6.7 ms, p95 18 ms). Two changes remove that:
 *      1. framework.session.metadata_update_threshold stops the metadata bag from changing the
 *         data on every request, and updateTimestamp() below does not rewrite an unchanged row,
 *         so a request writes only when something in the session actually changed.
 *      2. The writes that remain do not wait for the flush (synchronous_commit off, for that
 *         transaction only). Session rows are disposable - a database crash may lose the last
 *         fraction of a second of them, which remember-me covers.
 *
 * Never blocking: reads are plain SELECTs without locks, like PdoSessionHandler's LOCK_NONE, so
 * concurrent requests of one visitor (Live Component actions, Turbo frames) never wait for each
 * other. The one lock left is the row lock of a write, held from the upsert to the commit - and
 * with the flush gone that commit takes a round trip, not milliseconds, so concurrent writers of
 * the same session no longer queue behind each other's WAL flush as they did before.
 *
 * Same table, same columns, same data as PdoSessionHandler: switching signs nobody out, and the
 * old and new containers of a blue-green rollout share the rows. The table stays in Doctrine's
 * schema through the PdoSessionHandlerSchemaListener registered in config/services.php.
 *
 * Deliberately not a persistent PDO connection (PDO::ATTR_PERSISTENT) of its own: FrankenPHP does
 * not close those when it restarts a thread (max_requests), each restart would leave a backend
 * behind until the container stops.
 */
final class PostgresSessionHandler extends AbstractSessionHandler implements ResetInterface
{
    private const string UPSERT = 'INSERT INTO sessions (sess_id, sess_data, sess_lifetime, sess_time) VALUES (?, ?, ?, ?)
        ON CONFLICT (sess_id) DO UPDATE SET sess_data = EXCLUDED.sess_data, sess_lifetime = EXCLUDED.sess_lifetime, sess_time = EXCLUDED.sess_time';

    private bool $gcCalled = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly int $lifetimeSeconds,
    ) {
    }

    /**
     * PHP asks this before read() (session.use_strict_mode). The parent would read here and keep the data on this
     * object until read() takes it - but this object outlives requests (FrankenPHP worker), and nothing a visitor's
     * request read may stay on it for the next visitor: a leftover empty prefetch is even handed to ANY other id
     * by the parent's read(). So: read, answer, keep nothing. Costs one more primary-key SELECT (~0.2 ms) per
     * session start.
     */
    public function validateId(#[\SensitiveParameter] string $sessionId): bool
    {
        return $this->doRead($sessionId) !== '';
    }

    protected function doRead(#[\SensitiveParameter] string $sessionId): string
    {
        $row = $this->connection->fetchNumeric(
            'SELECT sess_data, sess_lifetime FROM sessions WHERE sess_id = ?',
            [$sessionId],
        );

        if ($row === false) {
            return '';
        }

        [$data, $expiresAt] = $row;

        // Expired but not collected yet: PHP must see a new, empty session
        if (!is_numeric($expiresAt) || (int) $expiresAt < $this->now()) {
            return '';
        }

        // bytea arrives as a stream from pdo_pgsql
        if (is_resource($data)) {
            $data = stream_get_contents($data);
        }

        return is_string($data) ? $data : '';
    }

    protected function doWrite(#[\SensitiveParameter] string $sessionId, string $data): bool
    {
        $now = $this->now();

        $this->writeWithoutWaitingForFlush(
            self::UPSERT,
            [$sessionId, $data, $now + $this->lifetimeSeconds, $now],
            [ParameterType::STRING, ParameterType::LARGE_OBJECT, ParameterType::INTEGER, ParameterType::INTEGER],
        );

        return true;
    }

    protected function doDestroy(#[\SensitiveParameter] string $sessionId): bool
    {
        $this->writeWithoutWaitingForFlush('DELETE FROM sessions WHERE sess_id = ?', [$sessionId], [ParameterType::STRING]);

        return true;
    }

    /**
     * PHP calls this instead of write() when nothing in the session changed (session.lazy_write).
     *
     * Deliberately no query - this used to be an UPDATE of the expiry on nearly every request. The
     * row still slides: metadata_update_threshold changes the data, and so write() refreshes the
     * expiry, at least once per threshold while the visitor is active. The 30-day window can
     * therefore end up to that threshold early, which the renewal of the cookies (at most daily,
     * SlidingLoginCookiesSubscriber) already allows for. The parent still destroys an emptied session.
     */
    public function updateTimestamp(#[\SensitiveParameter] string $sessionId, string $data): bool
    {
        return parent::updateTimestamp($sessionId, $data);
    }

    /**
     * PHP calls gc() right after read(); like PdoSessionHandler, the delete waits for close() so it
     * never runs between reading and writing the session.
     */
    public function gc(int $maxlifetime): int
    {
        $this->gcCalled = true;

        return 0;
    }

    public function close(): bool
    {
        if ($this->gcCalled) {
            $this->gcCalled = false;
            $this->writeWithoutWaitingForFlush('DELETE FROM sessions WHERE sess_lifetime < ?', [$this->now()], [ParameterType::INTEGER]);
        }

        // The connection is the worker's, it stays open for the next request
        return true;
    }

    /**
     * A session that was never closed (the request died between read and write) must not hand its
     * pending garbage collection to the next request of this worker.
     */
    public function reset(): void
    {
        $this->gcCalled = false;
    }

    /**
     * @param list<mixed> $params
     * @param list<ParameterType> $types
     */
    private function writeWithoutWaitingForFlush(string $sql, array $params, array $types): void
    {
        // The session is saved on kernel.response, after every handler's transaction has ended, so an
        // open one here was left behind by a bug - and in a worker it would swallow this worker's
        // writes until the connection is recycled. Write within it (SET LOCAL would make its commit
        // asynchronous too) and make sure somebody hears about it.
        if ($this->connection->isTransactionActive()) {
            $this->logger->warning('Session written inside an open database transaction - a transaction was left open', [
                'nesting_level' => $this->connection->getTransactionNestingLevel(),
            ]);
            $this->connection->executeStatement($sql, $params, $types);

            return;
        }

        $this->connection->transactional(static function (Connection $connection) use ($sql, $params, $types): void {
            $connection->executeStatement('SET LOCAL synchronous_commit TO OFF');
            $connection->executeStatement($sql, $params, $types);
        });
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
