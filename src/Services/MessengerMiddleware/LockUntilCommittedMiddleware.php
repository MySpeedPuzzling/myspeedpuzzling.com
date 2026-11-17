<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\MessengerMiddleware;

use Doctrine\DBAL\Exception\ConnectionLost;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Serializes handling of SerializedByLock messages per lock key, for the whole
 * transaction - see SerializedByLock for why the handler itself can not do this.
 *
 * Must stay BEFORE (outside) doctrine_transaction in the bus middleware list, so the
 * lock is released only after the transaction committed or rolled back. A message
 * dispatched from inside another handler's transaction would release its lock before
 * the outer commit - so a SerializedByLock message is dispatched that way only by a
 * composite that is SerializedByLock under the same key itself (the event creation
 * wizard's SaveEventWizardBasics / SetUpEventWizardRound around EditCompetitionRound):
 * a key this process holds already passes through - the outer message keeps it until
 * its own commit, and acquiring it a second time would wait for itself. A composite whose
 * nested messages use several keys holds them all (SerializedByLocks).
 */
final class LockUntilCommittedMiddleware implements MiddlewareInterface, ResetInterface
{
    /**
     * The keys held by messages being handled right now (outer ones of a nested dispatch)
     *
     * @var array<string, true>
     */
    private array $heldKeys = [];

    public function __construct(
        readonly private LockFactory $lockFactory,
        readonly private LoggerInterface $logger,
    ) {
    }

    public function reset(): void
    {
        $this->heldKeys = [];
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();

        if (!$message instanceof SerializedByLock) {
            return $stack->next()->handle($envelope, $stack);
        }

        // A composite holding several keys (SerializedByLocks): its own key first - the order every other composite
        // takes them in (the event's key held, a nested message's key acquired inside) - then the others sorted, so two
        // such composites never wait for each other crosswise
        $keys = [$message->lockKey()];

        if ($message instanceof SerializedByLocks) {
            $more = array_values(array_diff(array_unique($message->moreLockKeys()), $keys));
            sort($more);
            $keys = [...$keys, ...$more];
        }

        // Held by the outer message of a nested dispatch - it stays held until that one's commit
        $keys = array_values(array_filter($keys, fn (string $key): bool => isset($this->heldKeys[$key]) === false));

        if ($keys === []) {
            return $stack->next()->handle($envelope, $stack);
        }

        /** @var array<string, LockInterface> $locks */
        $locks = [];

        try {
            foreach ($keys as $key) {
                $lock = $this->lockFactory->createLock($key);
                $this->acquire($lock, $key);
                $locks[$key] = $lock;
                $this->heldKeys[$key] = true;
            }

            return $stack->next()->handle($envelope, $stack);
        } finally {
            foreach ($locks as $key => $lock) {
                unset($this->heldKeys[$key]);

                try {
                    $lock->release();
                } catch (LockReleasingException $releasingException) {
                    // The work is already committed (or rolled back) at this point; failing the
                    // request now would only make the caller retry something that succeeded.
                    // An advisory lock that could not be released dies with its connection.
                    $this->logger->warning('Could not release message lock', [
                        'lock_key' => $key,
                        'exception' => $releasingException,
                    ]);
                }
            }
        }
    }

    /**
     * The Postgres lock store keeps its own long-lived connection, which nothing else in the
     * worker keeps healthy: after the database restarts, the first lock of every worker fails
     * with ConnectionLost. DBAL closes a lost connection, so a second attempt reconnects.
     */
    private function acquire(LockInterface $lock, string $lockKey): void
    {
        try {
            $lock->acquire(blocking: true);
        } catch (LockAcquiringException $exception) {
            if (!$exception->getPrevious() instanceof ConnectionLost) {
                throw $exception;
            }

            $this->logger->notice('Lock store connection was lost, reconnecting', [
                'lock_key' => $lockKey,
                'exception' => $exception,
            ]);

            $lock->acquire(blocking: true);
        }
    }
}
