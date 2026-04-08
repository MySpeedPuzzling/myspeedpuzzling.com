<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Entity;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Referral;
use SpeedPuzzling\Web\Value\ReferralSource;

final class ReferralTest extends TestCase
{
    public function testConstructionSetsAllFields(): void
    {
        $affiliatePlayer = $this->createMock(Player::class);
        $subscriber = $this->createMock(Player::class);
        $now = new DateTimeImmutable('2026-01-15');

        $referral = new Referral(
            id: Uuid::uuid7(),
            subscriber: $subscriber,
            affiliatePlayer: $affiliatePlayer,
            source: ReferralSource::Link,
            createdAt: $now,
        );

        self::assertSame($affiliatePlayer, $referral->affiliatePlayer);
        self::assertSame($subscriber, $referral->subscriber);
        self::assertSame(ReferralSource::Link, $referral->source);
        self::assertSame($now, $referral->createdAt);
        self::assertSame($now, $referral->updatedAt);
    }

    public function testChangeAffiliatePlayerUpdatesFieldsAndTimestamp(): void
    {
        $originalPlayer = $this->createMock(Player::class);
        $newPlayer = $this->createMock(Player::class);
        $subscriber = $this->createMock(Player::class);
        $createdAt = new DateTimeImmutable('2026-01-15');
        $updatedAt = new DateTimeImmutable('2026-02-20');

        $referral = new Referral(
            id: Uuid::uuid7(),
            subscriber: $subscriber,
            affiliatePlayer: $originalPlayer,
            source: ReferralSource::Link,
            createdAt: $createdAt,
        );

        $referral->changeAffiliatePlayer($newPlayer, ReferralSource::Manual, $updatedAt);

        self::assertSame($newPlayer, $referral->affiliatePlayer);
        self::assertSame(ReferralSource::Manual, $referral->source);
        self::assertSame($updatedAt, $referral->updatedAt);
        self::assertSame($createdAt, $referral->createdAt);
    }
}
