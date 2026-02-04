<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Entity;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Voucher;
use SpeedPuzzling\Web\Value\VoucherType;

final class VoucherTest extends TestCase
{
    public function testVoucherIsNotUsedInitially(): void
    {
        $voucher = $this->createVoucher();

        self::assertFalse($voucher->isUsed());
        self::assertNull($voucher->usedAt);
        self::assertNull($voucher->usedBy);
    }

    public function testMarkAsUsed(): void
    {
        $voucher = $this->createVoucher();
        $player = $this->createPlayer();
        $usedAt = new DateTimeImmutable('2026-01-15 14:30:00');

        $voucher->markAsUsed($player, $usedAt);

        self::assertTrue($voucher->isUsed());
        self::assertSame($usedAt, $voucher->usedAt);
        self::assertSame($player, $voucher->usedBy);
    }

    public function testIsExpiredReturnsTrueWhenValidUntilIsPast(): void
    {
        $voucher = new Voucher(
            id: Uuid::uuid7(),
            code: 'TESTCODE12345678',
            monthsValue: 1,
            validUntil: new DateTimeImmutable('-5 days'),
            createdAt: new DateTimeImmutable('-30 days'),
        );

        self::assertTrue($voucher->isExpired(new DateTimeImmutable()));
    }

    public function testIsExpiredReturnsFalseWhenValidUntilIsFuture(): void
    {
        $voucher = new Voucher(
            id: Uuid::uuid7(),
            code: 'TESTCODE12345678',
            monthsValue: 1,
            validUntil: new DateTimeImmutable('+30 days'),
            createdAt: new DateTimeImmutable('-5 days'),
        );

        self::assertFalse($voucher->isExpired(new DateTimeImmutable()));
    }

    public function testIsAvailableWhenNotUsedAndNotExpired(): void
    {
        $voucher = new Voucher(
            id: Uuid::uuid7(),
            code: 'TESTCODE12345678',
            monthsValue: 1,
            validUntil: new DateTimeImmutable('+30 days'),
            createdAt: new DateTimeImmutable('-5 days'),
        );

        self::assertTrue($voucher->isAvailable(new DateTimeImmutable()));
    }

    public function testIsNotAvailableWhenUsed(): void
    {
        $voucher = $this->createVoucher();
        $player = $this->createPlayer();
        $voucher->markAsUsed($player, new DateTimeImmutable());

        self::assertFalse($voucher->isAvailable(new DateTimeImmutable()));
    }

    public function testIsNotAvailableWhenExpired(): void
    {
        $voucher = new Voucher(
            id: Uuid::uuid7(),
            code: 'TESTCODE12345678',
            monthsValue: 1,
            validUntil: new DateTimeImmutable('-5 days'),
            createdAt: new DateTimeImmutable('-30 days'),
        );

        self::assertFalse($voucher->isAvailable(new DateTimeImmutable()));
    }

    public function testIsFreeMonthsReturnsTrueForFreeMonthsVoucher(): void
    {
        $voucher = $this->createVoucher();

        self::assertTrue($voucher->isFreeMonths());
        self::assertFalse($voucher->isPercentageDiscount());
    }

    public function testIsPercentageDiscountReturnsTrueForPercentageVoucher(): void
    {
        $voucher = $this->createPercentageVoucher();

        self::assertTrue($voucher->isPercentageDiscount());
        self::assertFalse($voucher->isFreeMonths());
    }

    public function testHasRemainingUsesReturnsTrueWhenUnderLimit(): void
    {
        $voucher = new Voucher(
            id: Uuid::uuid7(),
            code: 'TESTCODE12345678',
            monthsValue: null,
            validUntil: new DateTimeImmutable('+30 days'),
            createdAt: new DateTimeImmutable(),
            voucherType: VoucherType::PercentageDiscount,
            percentageDiscount: 20,
            maxUses: 100,
        );

        self::assertTrue($voucher->hasRemainingUses(0));
        self::assertTrue($voucher->hasRemainingUses(50));
        self::assertTrue($voucher->hasRemainingUses(99));
    }

    public function testHasRemainingUsesReturnsFalseWhenAtOrOverLimit(): void
    {
        $voucher = new Voucher(
            id: Uuid::uuid7(),
            code: 'TESTCODE12345678',
            monthsValue: null,
            validUntil: new DateTimeImmutable('+30 days'),
            createdAt: new DateTimeImmutable(),
            voucherType: VoucherType::PercentageDiscount,
            percentageDiscount: 20,
            maxUses: 5,
        );

        self::assertFalse($voucher->hasRemainingUses(5));
        self::assertFalse($voucher->hasRemainingUses(10));
    }

    public function testSetStripeCouponId(): void
    {
        $voucher = $this->createPercentageVoucher();

        self::assertNull($voucher->stripeCouponId);

        $voucher->setStripeCouponId('coupon_abc123');

        self::assertSame('coupon_abc123', $voucher->stripeCouponId);
    }

    public function testPercentageVoucherHasNoMonthsValue(): void
    {
        $voucher = $this->createPercentageVoucher();

        self::assertNull($voucher->monthsValue);
        self::assertSame(20, $voucher->percentageDiscount);
    }

    private function createVoucher(): Voucher
    {
        return new Voucher(
            id: Uuid::uuid7(),
            code: 'TESTCODE12345678',
            monthsValue: 1,
            validUntil: new DateTimeImmutable('+30 days'),
            createdAt: new DateTimeImmutable(),
            internalNote: 'Test voucher',
        );
    }

    private function createPercentageVoucher(): Voucher
    {
        return new Voucher(
            id: Uuid::uuid7(),
            code: 'DISCOUNT20PRCT12',
            monthsValue: null,
            validUntil: new DateTimeImmutable('+30 days'),
            createdAt: new DateTimeImmutable(),
            internalNote: 'Test percentage voucher',
            voucherType: VoucherType::PercentageDiscount,
            percentageDiscount: 20,
            maxUses: 100,
        );
    }

    private function createPlayer(): Player
    {
        return new Player(
            id: Uuid::uuid7(),
            code: 'testuser',
            userId: 'auth0|test123',
            email: 'test@example.com',
            name: 'Test User',
            registeredAt: new DateTimeImmutable(),
        );
    }
}
