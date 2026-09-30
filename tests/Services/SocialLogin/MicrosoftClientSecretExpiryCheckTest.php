<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\SocialLogin;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\SocialLogin\MicrosoftClientSecretExpiryCheck;
use SpeedPuzzling\Web\Tests\TestDouble\InMemoryLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;

/**
 * MICROSOFT_CLIENT_SECRET_EXPIRES_AT turns a silent outage into a reminder:
 * a warning (-> Sentry) from 30 days before, an error once expired - each at
 * most once per day, however many requests run the check.
 */
final class MicrosoftClientSecretExpiryCheckTest extends TestCase
{
    private MockClock $clock;
    private ArrayAdapter $cache;
    private InMemoryLogger $logger;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-10-01 12:00:00', 'UTC');
        $this->cache = new ArrayAdapter();
        $this->logger = new InMemoryLogger();
    }

    public function testNothingWithoutAnExpiryDate(): void
    {
        $this->check('')->check();

        self::assertSame([], $this->logger->records);
    }

    public function testNothingWhenMicrosoftIsNotConfigured(): void
    {
        $this->check('2026-10-05', clientId: '')->check();

        self::assertSame([], $this->logger->records);
    }

    public function testNothingMoreThanThirtyDaysAhead(): void
    {
        $this->check('2026-11-01')->check();

        self::assertSame([], $this->logger->records);
    }

    public function testWarnsOncePerDayInTheLastThirtyDays(): void
    {
        $check = $this->check('2026-10-21');

        for ($i = 0; $i < 50; $i++) {
            $check->check();
        }

        self::assertCount(1, $this->logger->records);
        self::assertTrue($this->logger->hasRecord('warning', 'Microsoft client secret expires in 20 days'));

        // The next day reminds again
        $this->clock->modify('+1 day');
        $check->check();
        $check->check();

        self::assertCount(2, $this->logger->records);
        self::assertTrue($this->logger->hasRecord('warning', 'expires in 19 days'));
    }

    public function testErrorOnceExpired(): void
    {
        $check = $this->check('2026-10-01');

        $check->check();
        $check->check();

        self::assertCount(1, $this->logger->records);
        self::assertTrue($this->logger->hasRecord('error', 'Microsoft client secret has expired'));
    }

    public function testMalformedDateIsReportedOncePerDay(): void
    {
        $check = $this->check('01.10.2027');

        $check->check();
        $check->check();

        self::assertCount(1, $this->logger->records);
        self::assertTrue($this->logger->hasRecord('warning', 'MICROSOFT_CLIENT_SECRET_EXPIRES_AT is not a YYYY-MM-DD date'));
    }

    private function check(string $expiresAt, string $clientId = 'client-id'): MicrosoftClientSecretExpiryCheck
    {
        return new MicrosoftClientSecretExpiryCheck(
            $this->cache,
            $this->clock,
            $this->logger,
            $clientId,
            'client-secret',
            $expiresAt,
        );
    }
}
