<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use SpeedPuzzling\Web\Services\Xp\XpLaunchReadiness;

/**
 * The tests run launched (the switch on) and the switch costs no statement - like production, where the answer comes
 * from Redis, so the query budgets stay what production pays. A test of the time before the launch sets
 * `$ready = false` and puts it back in tearDown (static: it has to outlive the kernel reboots between requests).
 * CachedXpLaunchReadiness itself is tested against the database marker.
 */
final class FixedXpLaunchReadiness implements XpLaunchReadiness
{
    public static bool $ready = true;

    public function isReady(): bool
    {
        return self::$ready;
    }

    public function forget(): void
    {
    }
}
