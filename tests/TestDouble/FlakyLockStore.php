<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use Symfony\Component\Lock\BlockingStoreInterface;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\Store\InMemoryStore;
use Throwable;

/**
 * A blocking lock store that fails the first acquisitions with the given exceptions, the
 * way the Postgres advisory-lock store fails when its connection died underneath it, and
 * behaves like an in-memory store afterwards.
 */
final class FlakyLockStore implements BlockingStoreInterface
{
    public int $saveAttempts = 0;

    private readonly InMemoryStore $store;

    /**
     * @param list<Throwable> $failures
     */
    public function __construct(
        private array $failures,
    ) {
        $this->store = new InMemoryStore();
    }

    public function waitAndSave(Key $key): void
    {
        $this->save($key);
    }

    public function save(Key $key): void
    {
        $this->saveAttempts++;

        $failure = array_shift($this->failures);

        if ($failure !== null) {
            throw $failure;
        }

        $this->store->save($key);
    }

    public function delete(Key $key): void
    {
        $this->store->delete($key);
    }

    public function exists(Key $key): bool
    {
        return $this->store->exists($key);
    }

    public function putOffExpiration(Key $key, float $ttl): void
    {
        $this->store->putOffExpiration($key, $ttl);
    }
}
