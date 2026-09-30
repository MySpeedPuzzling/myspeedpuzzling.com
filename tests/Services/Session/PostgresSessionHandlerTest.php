<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Session;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Tools\DsnParser;
use SpeedPuzzling\Web\Services\Session\PostgresSessionHandler;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Component\Clock\MockClock;

/**
 * Most tests drive the handler on a connection of their own, outside the per-test transaction,
 * because what they check - commits, locks, what another connection sees - only exists for real
 * commits. Their rows are removed in tearDown().
 */
final class PostgresSessionHandlerTest extends KernelTestCase
{
    private const int LIFETIME = 2592000;

    private MockClock $clock;
    private TestHandler $logs;
    private Connection $connection;

    /** @var list<Connection> */
    private array $openedConnections = [];

    /** @var list<string> */
    private array $sessionIds = [];

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-09-30 12:00:00');
        $this->logs = new TestHandler();
        $this->connection = $this->newConnection();
    }

    protected function tearDown(): void
    {
        // Closing first ends whatever transaction a failed test left open, and with it its row locks,
        // which the cleanup below would otherwise wait for
        foreach ($this->openedConnections as $connection) {
            $connection->close();
        }

        $this->openedConnections = [];

        if ($this->sessionIds !== []) {
            $cleanup = $this->newConnection();
            $cleanup->executeStatement(
                'DELETE FROM sessions WHERE sess_id IN (?)',
                [$this->sessionIds],
                [ArrayParameterType::STRING],
            );
            $cleanup->close();
        }

        parent::tearDown();
    }

    public function testReadsBackExactlyWhatItWrote(): void
    {
        $sessionId = $this->sessionId();
        // Session data is binary-safe: serialized objects may carry NUL bytes
        $data = "_sf2_attributes|a:1:{s:4:\"name\";s:5:\"Jan \x00\";}\xC5\xBE";

        $this->handler()->write($sessionId, $data);

        self::assertSame($data, $this->handler()->read($sessionId));
    }

    public function testUnknownSessionReadsAsEmpty(): void
    {
        self::assertSame('', $this->handler()->read($this->sessionId()));
    }

    public function testRowLivesForTheLoginLifetimeFromItsLastWrite(): void
    {
        $sessionId = $this->sessionId();
        $this->handler()->write($sessionId, 'data');

        self::assertSame([
            'sess_lifetime' => $this->clock->now()->getTimestamp() + self::LIFETIME,
            'sess_time' => $this->clock->now()->getTimestamp(),
        ], $this->row($sessionId));

        $this->clock->sleep(self::LIFETIME - 1);
        self::assertSame('data', $this->handler()->read($sessionId));

        $this->clock->sleep(2);
        self::assertSame('', $this->handler()->read($sessionId), 'An expired row must read as a new, empty session');
    }

    public function testUnchangedSessionIsNotWrittenBack(): void
    {
        $sessionId = $this->sessionId();
        $this->handler()->write($sessionId, 'data');
        $before = $this->row($sessionId);

        // PHP calls updateTimestamp() instead of write() when the data did not change. The row
        // still slides because metadata_update_threshold changes the data at least hourly.
        $this->clock->sleep(600);
        self::assertTrue($this->handler()->updateTimestamp($sessionId, 'data'));

        self::assertSame($before, $this->row($sessionId));
    }

    public function testMetadataIsRestampedAtMostHourly(): void
    {
        // The other half of testUnchangedSessionIsNotWrittenBack(): without the threshold the
        // metadata bag changes the data on every request, and every request writes again
        self::assertSame(3600, self::getContainer()->getParameter('session.metadata.update_threshold'));
    }

    public function testEmptiedSessionIsDeleted(): void
    {
        $sessionId = $this->sessionId();
        $handler = $this->handler();
        $handler->write($sessionId, 'data');

        $handler->write($sessionId, '');

        self::assertNull($this->row($sessionId));
    }

    public function testDestroyDeletesTheRow(): void
    {
        $sessionId = $this->sessionId();
        $this->handler()->write($sessionId, 'data');

        $handler = $this->handler();
        $handler->read($sessionId);
        $handler->destroy($sessionId);

        self::assertNull($this->row($sessionId));
    }

    public function testGarbageCollectionDeletesOnlyExpiredRowsAndOnlyOnClose(): void
    {
        $expired = $this->sessionId();
        $live = $this->sessionId();
        $this->handler()->write($expired, 'old');
        $this->clock->sleep(self::LIFETIME + 1);
        $this->handler()->write($live, 'new');

        $handler = $this->handler();
        $handler->read($live);
        $handler->gc(self::LIFETIME);

        // PHP calls gc() between read() and write(); nothing may be deleted there
        self::assertNotNull($this->row($expired));

        $handler->close();

        self::assertNull($this->row($expired));
        self::assertNotNull($this->row($live));
    }

    public function testPendingGarbageCollectionDoesNotCarryOverToTheNextRequest(): void
    {
        $expired = $this->sessionId();
        $this->handler()->write($expired, 'old');
        $this->clock->sleep(self::LIFETIME + 1);

        // The worker's handler outlives requests: a request that died between read and close
        // must not leave its garbage collection to whichever request comes next
        $handler = $this->handler();
        $handler->gc(self::LIFETIME);
        $handler->reset();
        $handler->close();

        self::assertNotNull($this->row($expired));
    }

    /**
     * FrankenPHP keeps one handler object per worker for all requests, of all visitors. Interleaved requests of
     * different visitors on that one object must each see exactly their own session - never another's, never none.
     */
    public function testVisitorsNeverSeeEachOthersSessionOnTheWorkersOneHandler(): void
    {
        $alice = $this->sessionId();
        $bob = $this->sessionId();
        $stranger = $this->sessionId();
        $this->handler()->write($alice, 'player|s:5:"alice";');
        $this->handler()->write($bob, 'player|s:3:"bob";');

        $worker = $this->handler();

        foreach ([$alice, $bob, $stranger, $bob, $alice, $stranger, $alice] as $visitor) {
            // One request, the way PHP drives a handler under session.use_strict_mode
            $worker->open('', 'PHPSESSID');
            $known = $worker->validateId($visitor);
            $data = $worker->read($visitor);
            $worker->close();
            $worker->reset();

            $expected = match ($visitor) {
                $alice => 'player|s:5:"alice";',
                $bob => 'player|s:3:"bob";',
                default => '',
            };
            self::assertSame($expected, $data);
            self::assertSame($expected !== '', $known);
        }
    }

    /**
     * A request that dies between validateId() and read() - or any other leftover - must not leave anything on the
     * worker's handler. The parent class would keep the prefetched data and hand an empty one to ANY other id, which
     * would sign the next visitor out; this handler keeps nothing.
     */
    public function testNothingAVisitorReadStaysOnTheHandler(): void
    {
        $alice = $this->sessionId();
        $bob = $this->sessionId();
        $newcomer = $this->sessionId();
        $this->handler()->write($alice, 'player|s:5:"alice";');
        $this->handler()->write($bob, 'player|s:3:"bob";');

        $worker = $this->handler();

        // Alice's request validates her id and dies before reading
        self::assertTrue($worker->validateId($alice));
        // The next request on this worker is Bob's - he gets his own data, not Alice's
        self::assertSame('player|s:3:"bob";', $worker->read($bob));

        // A newcomer's request (no row yet) dies after validation
        self::assertFalse($worker->validateId($newcomer));
        // Bob's next request still sees his session, not the newcomer's empty one
        self::assertSame('player|s:3:"bob";', $worker->read($bob));
        // And a newcomer never gets anybody's data
        self::assertSame('', $worker->read($newcomer));
    }

    public function testReadingNeverWaitsForAConcurrentRequestOfTheSameVisitor(): void
    {
        $sessionId = $this->sessionId();
        $this->handler()->write($sessionId, 'data');

        // Another request of this visitor is in the middle of writing the row
        $other = $this->newConnection();
        $other->beginTransaction();
        $other->executeQuery('SELECT sess_id FROM sessions WHERE sess_id = ? FOR UPDATE', [$sessionId]);

        try {
            // Were the read to take a lock (PdoSessionHandler's default LOCK_TRANSACTIONAL), it would
            // wait for the other request to finish - and here fail with a lock timeout instead
            $this->connection->executeStatement("SET lock_timeout = '200ms'");

            self::assertSame('data', $this->handler()->read($sessionId));
        } finally {
            $other->rollBack();
        }
    }

    public function testWritingDoesNotWaitForTheWalFlush(): void
    {
        $sessionId = $this->sessionId();
        $this->handler()->write($sessionId, 'data');

        // Make every WAL flush of this connection take 100 ms more (superuser only)
        try {
            $this->connection->executeStatement('SET commit_delay = 100000');
            $this->connection->executeStatement('SET commit_siblings = 0');
        } catch (DbalException $exception) {
            self::markTestSkipped('commit_delay needs a superuser: ' . $exception->getMessage());
        }

        $startedAt = hrtime(true);
        $this->connection->executeStatement('UPDATE sessions SET sess_time = sess_time WHERE sess_id = ?', [$sessionId]);
        $synchronousMs = (hrtime(true) - $startedAt) / 1e6;

        if ($synchronousMs < 80) {
            self::markTestSkipped(sprintf('This server does not flush on commit (fsync off?) - a synchronous write took %.1f ms', $synchronousMs));
        }

        $startedAt = hrtime(true);
        $this->handler()->write($sessionId, 'changed');
        $sessionWriteMs = (hrtime(true) - $startedAt) / 1e6;

        // The row lock of a write lasts until its commit; a commit that waited for the flush is
        // what made concurrent requests of one visitor queue behind each other. Relative to the
        // synchronous write, so a slow machine does not make it flaky: 4 round trips vs 100+ ms.
        self::assertLessThan($synchronousMs / 2, $sessionWriteMs, sprintf(
            'The session write took %.1f ms, a synchronous write on the same connection %.1f ms',
            $sessionWriteMs,
            $synchronousMs,
        ));
        self::assertSame('changed', $this->handler()->read($sessionId));
    }

    public function testWriteInsideALeftOpenTransactionIsPartOfItAndReported(): void
    {
        $sessionId = $this->sessionId();

        $this->connection->beginTransaction();
        $this->handler()->write($sessionId, 'data');

        // Committed with the transaction, and without making that transaction's commit asynchronous
        self::assertSame('on', $this->connection->fetchOne("SELECT current_setting('synchronous_commit')"));
        self::assertNull($this->row($sessionId), 'Nobody else may see it before the transaction commits');
        self::assertTrue($this->logs->hasWarningThatContains('Session written inside an open database transaction'));

        $this->connection->rollBack();

        self::assertSame('', $this->handler()->read($sessionId));
    }

    public function testOrdinaryWritesReportNothing(): void
    {
        $sessionId = $this->sessionId();

        $this->handler()->write($sessionId, 'data');
        $this->handler()->destroy($sessionId);

        self::assertSame([], $this->logs->getRecords());
    }

    public function testSessionsLiveOnTheConnectionDoctrineAlreadyHolds(): void
    {
        $handler = self::getContainer()->get(PostgresSessionHandler::class);
        $doctrine = self::getContainer()->get('doctrine.dbal.default_connection');

        $sessionId = $this->sessionId();
        $handler->open('', 'PHPSESSID');
        $handler->write($sessionId, 'data');

        // This test runs inside a transaction that is never committed, so only the connection that
        // wrote the row can see it - proof that no second connection was opened for the session
        self::assertTrue($doctrine->fetchOne('SELECT EXISTS (SELECT 1 FROM sessions WHERE sess_id = ?)', [$sessionId]));
        self::assertNull($this->row($sessionId));
    }

    private function handler(): PostgresSessionHandler
    {
        $handler = new PostgresSessionHandler($this->connection, $this->clock, new Logger('test', [$this->logs]), self::LIFETIME);
        $handler->open('', 'PHPSESSID');

        return $handler;
    }

    /**
     * @return null|array{sess_lifetime: int, sess_time: int}
     */
    private function row(string $sessionId): null|array
    {
        $row = $this->newConnection()->fetchAssociative(
            'SELECT sess_lifetime, sess_time FROM sessions WHERE sess_id = ?',
            [$sessionId],
        );

        if ($row === false) {
            return null;
        }

        self::assertIsInt($row['sess_lifetime']);
        self::assertIsInt($row['sess_time']);

        return ['sess_lifetime' => $row['sess_lifetime'], 'sess_time' => $row['sess_time']];
    }

    private function sessionId(): string
    {
        $sessionId = 'test' . bin2hex(random_bytes(12));
        $this->sessionIds[] = $sessionId;

        return $sessionId;
    }

    private function newConnection(): Connection
    {
        $databaseUrl = $_SERVER['DATABASE_URL'] ?? null;
        self::assertIsString($databaseUrl);

        $connection = DriverManager::getConnection((new DsnParser([
            'postgres' => 'pdo_pgsql',
            'postgresql' => 'pdo_pgsql',
            'pgsql' => 'pdo_pgsql',
        ]))->parse($databaseUrl));

        $this->openedConnections[] = $connection;

        return $connection;
    }
}
