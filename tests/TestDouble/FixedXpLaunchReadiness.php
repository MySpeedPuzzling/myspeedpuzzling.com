<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use DateTimeImmutable;
use SpeedPuzzling\Web\Services\Xp\XpLaunchReadiness;
use SpeedPuzzling\Web\Tests\DataFixtures\XpSettingsFixture;

/**
 * The tests run launched (the switch on) and the switch costs no statement - like production, where the answer comes
 * from Redis, so the query budgets stay what production pays. A test of the time before the launch sets
 * `$ready = false` (static: it has to outlive the kernel reboots between requests); XpLaunchSwitchResetExtension puts
 * it back before every test.
 * CachedXpLaunchReadiness itself is tested against the database marker; XpLaunchReadinessStub is the instance double
 * for handlers a test builds by hand.
 */
final class FixedXpLaunchReadiness implements XpLaunchReadiness
{
    public static bool $ready = true;

    /** When the switch went on - a test may move it (reset before every test) */
    public static null|DateTimeImmutable $readyAt = null;

    public function isReady(): bool
    {
        return self::$ready;
    }

    public function readyAt(): null|DateTimeImmutable
    {
        return self::$ready ? (self::$readyAt ?? new DateTimeImmutable(XpSettingsFixture::LAUNCH_READY_AT)) : null;
    }

    public function forget(): void
    {
    }
}
