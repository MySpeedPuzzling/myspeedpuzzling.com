<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Entity;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Affiliate;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Value\AffiliateStatus;

final class AffiliateTest extends TestCase
{
    public function testNewAffiliateHasPendingStatus(): void
    {
        $affiliate = new Affiliate(
            id: Uuid::uuid7(),
            player: $this->createMock(Player::class),
            code: 'TEST001',
            createdAt: new DateTimeImmutable(),
        );

        self::assertSame(AffiliateStatus::Pending, $affiliate->status);
        self::assertFalse($affiliate->isActive());
    }

    public function testApproveChangesStatusToActive(): void
    {
        $affiliate = new Affiliate(
            id: Uuid::uuid7(),
            player: $this->createMock(Player::class),
            code: 'TEST001',
            createdAt: new DateTimeImmutable(),
        );

        $affiliate->approve();

        self::assertSame(AffiliateStatus::Active, $affiliate->status);
        self::assertTrue($affiliate->isActive());
    }

    public function testSuspendChangesStatusToSuspended(): void
    {
        $affiliate = new Affiliate(
            id: Uuid::uuid7(),
            player: $this->createMock(Player::class),
            code: 'TEST001',
            createdAt: new DateTimeImmutable(),
            status: AffiliateStatus::Active,
        );

        $affiliate->suspend();

        self::assertSame(AffiliateStatus::Suspended, $affiliate->status);
        self::assertFalse($affiliate->isActive());
    }

    public function testReactivateChangesStatusToActive(): void
    {
        $affiliate = new Affiliate(
            id: Uuid::uuid7(),
            player: $this->createMock(Player::class),
            code: 'TEST001',
            createdAt: new DateTimeImmutable(),
            status: AffiliateStatus::Suspended,
        );

        $affiliate->reactivate();

        self::assertSame(AffiliateStatus::Active, $affiliate->status);
        self::assertTrue($affiliate->isActive());
    }
}
