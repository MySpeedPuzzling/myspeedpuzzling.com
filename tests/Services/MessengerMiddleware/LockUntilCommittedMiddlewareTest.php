<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\MessengerMiddleware;

use Closure;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\ConnectionLost;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use SpeedPuzzling\Web\Services\MessengerMiddleware\LockUntilCommittedMiddleware;
use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLocks;
use SpeedPuzzling\Web\Tests\TestDouble\FlakyLockStore;
use SpeedPuzzling\Web\Tests\TestDouble\RecordingLockStore;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final class LockUntilCommittedMiddlewareTest extends TestCase
{
    private const string LOCK_KEY = 'test-lock-key';

    private LockFactory $lockFactory;

    protected function setUp(): void
    {
        $this->lockFactory = new LockFactory(new InMemoryStore());
    }

    public function testHoldsTheLockWhileTheRestOfTheStackRuns(): void
    {
        $lockWasFreeInside = null;
        $envelope = new Envelope($this->lockedMessage());

        // The rest of the stack is where doctrine_transaction flushes and commits
        $result = $this->middleware()->handle($envelope, $this->stack(function () use (&$lockWasFreeInside, $envelope): Envelope {
            $lockWasFreeInside = $this->isLockFree();

            return $envelope;
        }));

        self::assertSame($envelope, $result);
        self::assertFalse($lockWasFreeInside);
        self::assertTrue($this->isLockFree(), 'The lock must be released once the stack is done');
    }

    public function testReleasesTheLockWhenHandlingFails(): void
    {
        $failure = new RuntimeException('handler failed');

        try {
            $this->middleware()->handle(new Envelope($this->lockedMessage()), $this->stack(static function () use ($failure): never {
                throw $failure;
            }));
            self::fail('Expected the failure to propagate');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertTrue($this->isLockFree());
    }

    public function testLeavesOtherMessagesAlone(): void
    {
        $lockWasFreeInside = null;
        $envelope = new Envelope(new \stdClass());

        $result = $this->middleware()->handle($envelope, $this->stack(function () use (&$lockWasFreeInside, $envelope): Envelope {
            $lockWasFreeInside = $this->isLockFree();

            return $envelope;
        }));

        self::assertSame($envelope, $result);
        self::assertTrue($lockWasFreeInside);
    }

    /**
     * A composite holding the key dispatches a message of the same key from inside its handler (the event creation
     * wizard's SetUpEventWizardRound around EditCompetitionRound): the inner one passes through instead of waiting for
     * the outer one, and the key stays held until the outer one is done.
     */
    public function testANestedMessageOfAHeldKeyPassesThroughAndTheOuterKeepsTheLock(): void
    {
        $middleware = $this->middleware();
        $innerHandled = false;
        $lockFreeAfterInner = null;
        $outer = new Envelope($this->lockedMessage());
        $inner = new Envelope($this->lockedMessage());

        $middleware->handle($outer, $this->stack(function () use ($middleware, $inner, $outer, &$innerHandled, &$lockFreeAfterInner): Envelope {
            $middleware->handle($inner, $this->stack(static function () use (&$innerHandled, $inner): Envelope {
                $innerHandled = true;

                return $inner;
            }));
            $lockFreeAfterInner = $this->isLockFree();

            return $outer;
        }));

        self::assertTrue($innerHandled);
        self::assertFalse($lockFreeAfterInner, 'The outer message keeps the lock until it is done');
        self::assertTrue($this->isLockFree());

        // The next message acquires it as usual
        $middleware->handle($inner, $this->stack(function () use ($inner, &$lockFreeAfterInner): Envelope {
            $lockFreeAfterInner = $this->isLockFree();

            return $inner;
        }));
        self::assertFalse($lockFreeAfterInner);
    }

    /**
     * A composite whose nested messages use several keys (SerializedByLocks - the creation wizard's Extras) holds every
     * one of them until it is done: a nested message of any of them passes through, none is released in between.
     */
    public function testACompositeHoldsEveryKeyOfItsNestedMessagesUntilItIsDone(): void
    {
        $middleware = $this->middleware();
        $composite = new Envelope(new class (self::LOCK_KEY) implements SerializedByLocks {
            public function __construct(private string $key)
            {
            }

            public function lockKey(): string
            {
                return $this->key;
            }

            public function moreLockKeys(): array
            {
                return ['other-key', $this->key, 'other-key'];
            }
        });
        $inner = new Envelope($this->lockedMessage('other-key'));
        $free = [];

        $middleware->handle($composite, $this->stack(function () use ($middleware, $inner, $composite, &$free): Envelope {
            $free['before'] = [$this->isLockFree(), $this->isLockFree('other-key')];
            $middleware->handle($inner, $this->stack(static fn (): Envelope => $inner));
            $free['after_inner'] = [$this->isLockFree(), $this->isLockFree('other-key')];

            return $composite;
        }));

        self::assertSame([false, false], $free['before']);
        self::assertSame([false, false], $free['after_inner'], 'The nested message must not release a key of the composite');
        self::assertTrue($this->isLockFree());
        self::assertTrue($this->isLockFree('other-key'));
    }

    /**
     * The composite's own key first (the order every composite takes keys in: the event's key held, a nested key
     * acquired inside), then the others sorted, each once - two multi-key composites never wait for each other crosswise.
     */
    public function testACompositeTakesItsOwnKeyFirstThenTheOthersSorted(): void
    {
        $store = $this->recordingStore();
        $this->lockFactory = new LockFactory($store);
        $composite = new Envelope($this->composite('m-event', ['z-displays', 'a-referees', 'z-displays', 'm-event']));

        $this->middleware()->handle($composite, $this->stack(static fn (): Envelope => $composite));

        self::assertSame(['m-event', 'a-referees', 'z-displays'], $store->saved);
        self::assertSame(['m-event', 'a-referees', 'z-displays'], $store->deleted);
    }

    /**
     * A key that cannot be taken after another one was: nothing is handled, the key already taken is released and the
     * middleware holds nothing afterwards (the next message of either key acquires it as usual).
     */
    public function testAFailureOnALaterKeyReleasesTheKeysAlreadyTaken(): void
    {
        $store = $this->recordingStore(failOn: 'z-displays');
        $this->lockFactory = new LockFactory($store);
        $middleware = $this->middleware();

        try {
            $middleware->handle(new Envelope($this->composite('m-event', ['z-displays'])), $this->stack(static function (): never {
                self::fail('Must not handle the message without every lock');
            }));
            self::fail('Expected the lock failure to propagate');
        } catch (LockAcquiringException) {
        }

        self::assertSame(['m-event', 'z-displays'], $store->saved);
        self::assertSame(['m-event'], $store->deleted);

        // Nothing is left marked as held: the next message of the key acquires it (it would pass through otherwise)
        $next = new Envelope($this->lockedMessage('m-event'));
        $middleware->handle($next, $this->stack(static fn (): Envelope => $next));
        self::assertSame(['m-event', 'z-displays', 'm-event'], $store->saved);
        self::assertSame(['m-event', 'm-event'], $store->deleted);
    }

    /**
     * A composite nested in another one that holds some of its keys already: only the keys not held yet are acquired,
     * and only those are released when the nested one is done - the outer keeps its own until its commit.
     */
    public function testANestedCompositeAcquiresAndReleasesOnlyTheKeysNotHeldYet(): void
    {
        $store = $this->recordingStore();
        $this->lockFactory = new LockFactory($store);
        $middleware = $this->middleware();
        $outer = new Envelope($this->lockedMessage('a-event'));
        $inner = new Envelope($this->composite('a-event', ['b-displays']));
        $during = [];

        $middleware->handle($outer, $this->stack(function () use ($middleware, $outer, $inner, $store, &$during): Envelope {
            $middleware->handle($inner, $this->stack(static function () use ($inner, $store, &$during): Envelope {
                $during['saved_inside_inner'] = $store->saved;

                return $inner;
            }));
            $during['deleted_after_inner'] = $store->deleted;
            $during['event_free_after_inner'] = $this->isLockFree('a-event');

            return $outer;
        }));

        self::assertSame(['a-event', 'b-displays'], $during['saved_inside_inner']);
        self::assertSame(['b-displays'], $during['deleted_after_inner']);
        self::assertFalse($during['event_free_after_inner'], 'The outer message keeps its key until it is done');
        self::assertTrue($this->isLockFree('a-event'));
        self::assertTrue($this->isLockFree('b-displays'));
    }

    /**
     * The lock store keeps its own long-lived database connection. After Postgres restarted,
     * the first lock of every worker failed with ConnectionLost (production 2026-09-03: a
     * Stripe webhook answered 500). DBAL closes a lost connection, so one more attempt
     * reconnects.
     */
    public function testRetriesOnceWhenTheLockStoreLostItsConnection(): void
    {
        $store = new FlakyLockStore(failures: [$this->connectionLost()]);
        $this->lockFactory = new LockFactory($store);
        $envelope = new Envelope($this->lockedMessage());
        $handled = false;

        $this->middleware()->handle($envelope, $this->stack(static function () use (&$handled, $envelope): Envelope {
            $handled = true;

            return $envelope;
        }));

        self::assertTrue($handled);
        self::assertSame(2, $store->saveAttempts);
    }

    public function testGivesUpWhenTheConnectionIsStillGone(): void
    {
        $store = new FlakyLockStore(failures: [$this->connectionLost(), $this->connectionLost()]);
        $this->lockFactory = new LockFactory($store);

        try {
            $this->middleware()->handle(new Envelope($this->lockedMessage()), $this->stack(static function (): never {
                self::fail('Must not handle the message without the lock');
            }));
            self::fail('Expected the lock failure to propagate');
        } catch (LockAcquiringException $caught) {
            self::assertInstanceOf(ConnectionLost::class, $caught->getPrevious());
        }

        self::assertSame(2, $store->saveAttempts);
    }

    public function testDoesNotRetryOtherLockFailures(): void
    {
        $store = new FlakyLockStore(failures: [new RuntimeException('something else'), $this->connectionLost()]);
        $this->lockFactory = new LockFactory($store);

        try {
            $this->middleware()->handle(new Envelope($this->lockedMessage()), $this->stack(static function (): never {
                self::fail('Must not handle the message without the lock');
            }));
            self::fail('Expected the lock failure to propagate');
        } catch (LockAcquiringException $caught) {
            self::assertInstanceOf(RuntimeException::class, $caught->getPrevious());
        }

        self::assertSame(1, $store->saveAttempts);
    }

    private function connectionLost(): ConnectionLost
    {
        $driverException = new class ('server closed the connection unexpectedly') extends AbstractException {
        };

        return new ConnectionLost($driverException, null);
    }

    private function middleware(): LockUntilCommittedMiddleware
    {
        return new LockUntilCommittedMiddleware($this->lockFactory, new NullLogger());
    }

    private function isLockFree(string $key = self::LOCK_KEY): bool
    {
        $probe = $this->lockFactory->createLock($key);

        if (!$probe->acquire(blocking: false)) {
            return false;
        }

        $probe->release();

        return true;
    }

    /**
     * @param list<string> $moreKeys
     */
    private function composite(string $key, array $moreKeys): SerializedByLocks
    {
        return new class ($key, $moreKeys) implements SerializedByLocks {
            /**
             * @param list<string> $moreKeys
             */
            public function __construct(private string $key, private array $moreKeys)
            {
            }

            public function lockKey(): string
            {
                return $this->key;
            }

            public function moreLockKeys(): array
            {
                return $this->moreKeys;
            }
        };
    }

    private function recordingStore(null|string $failOn = null): RecordingLockStore
    {
        return new RecordingLockStore($failOn);
    }

    private function lockedMessage(string $key = self::LOCK_KEY): SerializedByLock
    {
        return new class ($key) implements SerializedByLock {
            public function __construct(private string $key)
            {
            }

            public function lockKey(): string
            {
                return $this->key;
            }
        };
    }

    /**
     * @param Closure(): Envelope $handle
     */
    private function stack(Closure $handle): StackInterface
    {
        return new class ($handle) implements StackInterface, MiddlewareInterface {
            /**
             * @param Closure(): Envelope $handle
             */
            public function __construct(private Closure $handle)
            {
            }

            public function next(): MiddlewareInterface
            {
                return $this;
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                return ($this->handle)();
            }
        };
    }
}
