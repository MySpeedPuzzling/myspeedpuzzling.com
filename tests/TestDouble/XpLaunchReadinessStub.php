<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use DateTimeImmutable;
use SpeedPuzzling\Web\Services\Xp\XpLaunchReadiness;

/**
 * The XP launch switch for a handler or gate a test builds by hand: `new XpFeatureGate(new XpLaunchReadinessStub(false))`.
 */
final readonly class XpLaunchReadinessStub implements XpLaunchReadiness
{
    public function __construct(
        private bool $ready = true,
        private null|DateTimeImmutable $readyAt = null,
    ) {
    }

    public function isReady(): bool
    {
        return $this->ready;
    }

    public function readyAt(): null|DateTimeImmutable
    {
        return $this->ready ? ($this->readyAt ?? new DateTimeImmutable('2026-08-02 00:00:00')) : null;
    }

    public function stampedFrom(): null|DateTimeImmutable
    {
        return $this->ready ? new DateTimeImmutable('2026-08-01 00:00:00') : null;
    }

    public function forget(): void
    {
    }
}
