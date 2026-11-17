<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * The analytics studio's watch markers in tests (AnalyticsStudioWatch's pool, config/services_test.php): in memory and
 * STATIC on purpose - a marker set by a state request must survive to the write request after it (the browser's
 * kernel reboot resets every container service implementing ResetInterface, an array cache pool included - this
 * wrapper implements none and keeps its adapter outside the container), yet never outlive its test: the
 * AnalyticsStudioWatchResetExtension empties it before every test, so a round one test watched never makes another
 * test's results write publish a studio signal (the pinned update counts of other tests stay exact).
 */
final class AnalyticsStudioWatchPool implements CacheItemPoolInterface
{
    private static null|ArrayAdapter $adapter = null;

    public static function forget(): void
    {
        self::$adapter = null;
    }

    private static function adapter(): ArrayAdapter
    {
        return self::$adapter ??= new ArrayAdapter();
    }

    public function getItem(string $key): CacheItemInterface
    {
        return self::adapter()->getItem($key);
    }

    /**
     * @param array<string> $keys
     * @return iterable<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        return self::adapter()->getItems($keys);
    }

    public function hasItem(string $key): bool
    {
        return self::adapter()->hasItem($key);
    }

    public function clear(): bool
    {
        return self::adapter()->clear();
    }

    public function deleteItem(string $key): bool
    {
        return self::adapter()->deleteItem($key);
    }

    /**
     * @param array<string> $keys
     */
    public function deleteItems(array $keys): bool
    {
        return self::adapter()->deleteItems($keys);
    }

    public function save(CacheItemInterface $item): bool
    {
        return self::adapter()->save($item);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return self::adapter()->saveDeferred($item);
    }

    public function commit(): bool
    {
        return self::adapter()->commit();
    }
}
