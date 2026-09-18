<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\MessengerMiddleware;

/**
 * Messages whose handling must never overlap for the same key - typically because the
 * handler checks "does it exist yet?" before creating it, and the same message can arrive
 * twice at once (a Stripe webhook and the checkout-success page, a retried webhook, ...).
 *
 * LockUntilCommittedMiddleware holds the lock around the whole doctrine_transaction, so the
 * next handler for the key only starts once the previous one's changes are committed.
 * A lock taken inside the handler cannot do that: it is released when the handler returns,
 * before the middleware flushes and commits, which leaves the next handler reading stale data.
 */
interface SerializedByLock
{
    public function lockKey(): string;
}
