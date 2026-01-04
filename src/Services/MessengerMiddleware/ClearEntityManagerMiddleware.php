<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\MessengerMiddleware;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Clears the entity manager before handling messages that require fresh state.
 *
 * This middleware should be placed BEFORE doctrine_transaction middleware.
 * It ensures that events dispatched from postFlush don't encounter identity map
 * conflicts with entities loaded by previous handlers.
 */
final readonly class ClearEntityManagerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();

        if ($message instanceof RequiresFreshEntityManagerState) {
            // Only clear if no transaction is active
            // This prevents clearing during nested dispatches (which would lose parent's pending changes)
            // Events from postFlush are safe to clear because the parent transaction has already committed
            if (!$this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->clear();
            }
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
