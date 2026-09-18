<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\MessengerMiddleware;

use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Serializes handling of SerializedByLock messages per lock key, for the whole
 * transaction - see SerializedByLock for why the handler itself can not do this.
 *
 * Must stay BEFORE (outside) doctrine_transaction in the bus middleware list, so the
 * lock is released only after the transaction committed or rolled back. A message
 * dispatched from inside another handler's transaction would release its lock before
 * the outer commit - none of the SerializedByLock messages is dispatched that way.
 */
final readonly class LockUntilCommittedMiddleware implements MiddlewareInterface
{
    public function __construct(
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();

        if (!$message instanceof SerializedByLock) {
            return $stack->next()->handle($envelope, $stack);
        }

        $lock = $this->lockFactory->createLock($message->lockKey());
        $lock->acquire(blocking: true);

        try {
            return $stack->next()->handle($envelope, $stack);
        } finally {
            try {
                $lock->release();
            } catch (LockReleasingException $releasingException) {
                // The work is already committed (or rolled back) at this point; failing the
                // request now would only make the caller retry something that succeeded.
                // An advisory lock that could not be released dies with its connection.
                $this->logger->warning('Could not release message lock', [
                    'lock_key' => $message->lockKey(),
                    'exception' => $releasingException,
                ]);
            }
        }
    }
}
