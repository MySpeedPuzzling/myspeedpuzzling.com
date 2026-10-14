<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\MessengerMiddleware;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * The bus's transaction: Symfony's `doctrine_transaction` (begin, the handler, flush, commit - a rollback on any throw;
 * config/services.php hands it in) for every message, except a HandledOutsideTransaction one - its handler waits for a
 * slow external service and runs without a transaction (its write is a message of its own, in a transaction of its own).
 */
final readonly class TransactionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private MiddlewareInterface $transaction,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if ($envelope->getMessage() instanceof HandledOutsideTransaction) {
            return $stack->next()->handle($envelope, $stack);
        }

        return $this->transaction->handle($envelope, $stack);
    }
}
