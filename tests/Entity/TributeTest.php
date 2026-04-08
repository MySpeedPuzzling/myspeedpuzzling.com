<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Entity;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Affiliate;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Tribute;
use SpeedPuzzling\Web\Value\TributeSource;

final class TributeTest extends TestCase
{
    public function testConstructionSetsAllFields(): void
    {
        $affiliate = $this->createMock(Affiliate::class);
        $subscriber = $this->createMock(Player::class);
        $now = new DateTimeImmutable('2026-01-15');

        $tribute = new Tribute(
            id: Uuid::uuid7(),
            subscriber: $subscriber,
            affiliate: $affiliate,
            source: TributeSource::Link,
            createdAt: $now,
        );

        self::assertSame($affiliate, $tribute->affiliate);
        self::assertSame($subscriber, $tribute->subscriber);
        self::assertSame(TributeSource::Link, $tribute->source);
        self::assertSame($now, $tribute->createdAt);
        self::assertSame($now, $tribute->updatedAt);
    }

    public function testChangeAffiliateUpdatesFieldsAndTimestamp(): void
    {
        $originalAffiliate = $this->createMock(Affiliate::class);
        $newAffiliate = $this->createMock(Affiliate::class);
        $subscriber = $this->createMock(Player::class);
        $createdAt = new DateTimeImmutable('2026-01-15');
        $updatedAt = new DateTimeImmutable('2026-02-20');

        $tribute = new Tribute(
            id: Uuid::uuid7(),
            subscriber: $subscriber,
            affiliate: $originalAffiliate,
            source: TributeSource::Link,
            createdAt: $createdAt,
        );

        $tribute->changeAffiliate($newAffiliate, TributeSource::Manual, $updatedAt);

        self::assertSame($newAffiliate, $tribute->affiliate);
        self::assertSame(TributeSource::Manual, $tribute->source);
        self::assertSame($updatedAt, $tribute->updatedAt);
        self::assertSame($createdAt, $tribute->createdAt);
    }
}
