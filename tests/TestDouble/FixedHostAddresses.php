<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use SpeedPuzzling\Web\Services\DataImport\HostAddresses;

/**
 * Test replacement for the DNS lookup of the data import's Google fetch: every host resolves to one public address,
 * unless the test says otherwise. Static - the test browser reboots the kernel.
 */
final class FixedHostAddresses implements HostAddresses
{
    public const string PUBLIC_ADDRESS = '142.250.185.78';

    /** @var array<string, list<string>> */
    private static array $addresses = [];

    public function of(string $host): array
    {
        return self::$addresses[$host] ?? [self::PUBLIC_ADDRESS];
    }

    /**
     * @param list<string> $addresses
     */
    public static function resolve(string $host, array $addresses): void
    {
        self::$addresses[$host] = $addresses;
    }

    public static function reset(): void
    {
        self::$addresses = [];
    }
}
