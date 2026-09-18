<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\MessengerMiddleware;

use Closure;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use SpeedPuzzling\Web\Services\MessengerMiddleware\LockUntilCommittedMiddleware;
use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
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

    private function middleware(): LockUntilCommittedMiddleware
    {
        return new LockUntilCommittedMiddleware($this->lockFactory, new NullLogger());
    }

    private function isLockFree(): bool
    {
        $probe = $this->lockFactory->createLock(self::LOCK_KEY);

        if (!$probe->acquire(blocking: false)) {
            return false;
        }

        $probe->release();

        return true;
    }

    private function lockedMessage(): SerializedByLock
    {
        return new class (self::LOCK_KEY) implements SerializedByLock {
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
