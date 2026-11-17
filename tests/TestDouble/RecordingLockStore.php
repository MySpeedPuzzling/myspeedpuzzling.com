<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use RuntimeException;
use Symfony\Component\Lock\BlockingStoreInterface;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * A blocking in-memory lock store that records the order keys are taken and released in - and refuses one key, the way
 * a lock store fails a later acquisition of a composite holding several keys (LockUntilCommittedMiddleware).
 * Only successful releases of keys it saved are recorded; a probe of a free key (acquire + release) is recorded too.
 */
final class RecordingLockStore implements BlockingStoreInterface
{
    /** @var list<string> */
    public array $saved = [];

    /** @var list<string> */
    public array $deleted = [];

    private readonly InMemoryStore $store;

    public function __construct(
        private readonly null|string $failOn = null,
    ) {
        $this->store = new InMemoryStore();
    }

    public function waitAndSave(Key $key): void
    {
        $this->save($key);
    }

    public function save(Key $key): void
    {
        $this->saved[] = (string) $key;

        if ($this->failOn === (string) $key) {
            throw new RuntimeException(sprintf('Lock store refused "%s"', $key));
        }

        $this->store->save($key);
    }

    public function delete(Key $key): void
    {
        if ($this->store->exists($key)) {
            $this->deleted[] = (string) $key;
        }

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
