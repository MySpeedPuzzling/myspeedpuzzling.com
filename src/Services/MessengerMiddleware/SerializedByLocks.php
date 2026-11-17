<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\MessengerMiddleware;

/**
 * A composite that dispatches nested messages serialized by more than one key (the creation wizard's
 * SaveEventWizardExtras: the event's participants lock, its referees' and its displays' keys) - in one transaction, so
 * every key is held until that transaction committed. LockUntilCommittedMiddleware takes lockKey() first (like every
 * composite holding the event's key and acquiring a nested key inside), then these sorted; the nested messages pass
 * through as with one key.
 */
interface SerializedByLocks extends SerializedByLock
{
    /**
     * @return list<string> keys besides lockKey() - duplicates and lockKey() itself are ignored
     */
    public function moreLockKeys(): array;
}
