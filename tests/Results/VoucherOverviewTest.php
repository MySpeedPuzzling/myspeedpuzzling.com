<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Results;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\VoucherOverview;

final class VoucherOverviewTest extends TestCase
{
    #[DataProvider('maskedCodeProvider')]
    public function testGetMaskedCode(string $code, string $expected): void
    {
        $voucher = new VoucherOverview(
            id: '00000000-0000-0000-0000-000000000001',
            code: $code,
            monthsValue: 1,
            validUntil: new DateTimeImmutable('+30 days'),
            createdAt: new DateTimeImmutable(),
            usedAt: null,
            usedById: null,
            usedByName: null,
            internalNote: null,
        );

        self::assertSame($expected, $voucher->getMaskedCode());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function maskedCodeProvider(): iterable
    {
        // Pattern: first 4 chars visible, then asterisks, then last 2 chars visible
        // Masked length = total - 4 (start) - 2 (end)
        yield '16 char code' => ['ABCDEFGHIJ123456', 'ABCD**********56']; // 16-4-2 = 10 asterisks
        yield '8 char code' => ['ABCD1234', 'ABCD**34']; // 8-4-2 = 2 asterisks
        yield '10 char code' => ['ABCDEFGH12', 'ABCD****12']; // 10-4-2 = 4 asterisks
        yield '4 char code' => ['ABCD', '****']; // All masked when <= 4 chars
        yield '3 char code' => ['ABC', '***']; // All masked when <= 4 chars
        yield '32 char code' => ['ABCDEFGHIJKLMNOPQRSTUVWXYZ123456', 'ABCD**************************56']; // 32-4-2 = 26 asterisks
    }

    public function testIsUsedReturnsTrueWhenUsedAtIsSet(): void
    {
        $voucher = new VoucherOverview(
            id: '00000000-0000-0000-0000-000000000001',
            code: 'TESTCODE12345678',
            monthsValue: 1,
            validUntil: new DateTimeImmutable('+30 days'),
            createdAt: new DateTimeImmutable('-10 days'),
            usedAt: new DateTimeImmutable('-2 days'),
            usedById: '00000000-0000-0000-0000-000000000002',
            usedByName: 'John Doe',
            internalNote: null,
        );

        self::assertTrue($voucher->isUsed());
    }

    public function testIsUsedReturnsFalseWhenUsedAtIsNull(): void
    {
        $voucher = new VoucherOverview(
            id: '00000000-0000-0000-0000-000000000001',
            code: 'TESTCODE12345678',
            monthsValue: 1,
            validUntil: new DateTimeImmutable('+30 days'),
            createdAt: new DateTimeImmutable('-10 days'),
            usedAt: null,
            usedById: null,
            usedByName: null,
            internalNote: null,
        );

        self::assertFalse($voucher->isUsed());
    }

    public function testIsExpiredReturnsTrueWhenValidUntilIsPast(): void
    {
        $voucher = new VoucherOverview(
            id: '00000000-0000-0000-0000-000000000001',
            code: 'TESTCODE12345678',
            monthsValue: 1,
            validUntil: new DateTimeImmutable('-5 days'),
            createdAt: new DateTimeImmutable('-30 days'),
            usedAt: null,
            usedById: null,
            usedByName: null,
            internalNote: null,
        );

        self::assertTrue($voucher->isExpired(new DateTimeImmutable()));
    }

    public function testIsExpiredReturnsFalseWhenValidUntilIsFuture(): void
    {
        $voucher = new VoucherOverview(
            id: '00000000-0000-0000-0000-000000000001',
            code: 'TESTCODE12345678',
            monthsValue: 1,
            validUntil: new DateTimeImmutable('+30 days'),
            createdAt: new DateTimeImmutable('-10 days'),
            usedAt: null,
            usedById: null,
            usedByName: null,
            internalNote: null,
        );

        self::assertFalse($voucher->isExpired(new DateTimeImmutable()));
    }

    public function testIsAvailableReturnsTrueWhenNotUsedAndNotExpired(): void
    {
        $voucher = new VoucherOverview(
            id: '00000000-0000-0000-0000-000000000001',
            code: 'TESTCODE12345678',
            monthsValue: 1,
            validUntil: new DateTimeImmutable('+30 days'),
            createdAt: new DateTimeImmutable('-10 days'),
            usedAt: null,
            usedById: null,
            usedByName: null,
            internalNote: null,
        );

        self::assertTrue($voucher->isAvailable(new DateTimeImmutable()));
    }

    public function testIsAvailableReturnsFalseWhenUsed(): void
    {
        $voucher = new VoucherOverview(
            id: '00000000-0000-0000-0000-000000000001',
            code: 'TESTCODE12345678',
            monthsValue: 1,
            validUntil: new DateTimeImmutable('+30 days'),
            createdAt: new DateTimeImmutable('-10 days'),
            usedAt: new DateTimeImmutable('-2 days'),
            usedById: '00000000-0000-0000-0000-000000000002',
            usedByName: 'John Doe',
            internalNote: null,
        );

        self::assertFalse($voucher->isAvailable(new DateTimeImmutable()));
    }

    public function testIsAvailableReturnsFalseWhenExpired(): void
    {
        $voucher = new VoucherOverview(
            id: '00000000-0000-0000-0000-000000000001',
            code: 'TESTCODE12345678',
            monthsValue: 1,
            validUntil: new DateTimeImmutable('-5 days'),
            createdAt: new DateTimeImmutable('-30 days'),
            usedAt: null,
            usedById: null,
            usedByName: null,
            internalNote: null,
        );

        self::assertFalse($voucher->isAvailable(new DateTimeImmutable()));
    }

    public function testFromDatabaseRow(): void
    {
        $row = [
            'id' => '00000000-0000-0000-0000-000000000001',
            'code' => 'TESTCODE12345678',
            'months_value' => 3,
            'valid_until' => '2026-12-31 23:59:59',
            'created_at' => '2026-01-01 10:00:00',
            'used_at' => '2026-01-15 14:30:00',
            'used_by_id' => '00000000-0000-0000-0000-000000000002',
            'used_by_name' => 'John Doe',
            'internal_note' => 'Test note',
        ];

        $voucher = VoucherOverview::fromDatabaseRow($row);

        self::assertSame('00000000-0000-0000-0000-000000000001', $voucher->id);
        self::assertSame('TESTCODE12345678', $voucher->code);
        self::assertSame(3, $voucher->monthsValue);
        self::assertSame('2026-12-31', $voucher->validUntil->format('Y-m-d'));
        self::assertSame('2026-01-01', $voucher->createdAt->format('Y-m-d'));
        self::assertNotNull($voucher->usedAt);
        self::assertSame('2026-01-15', $voucher->usedAt->format('Y-m-d'));
        self::assertSame('00000000-0000-0000-0000-000000000002', $voucher->usedById);
        self::assertSame('John Doe', $voucher->usedByName);
        self::assertSame('Test note', $voucher->internalNote);
    }

    public function testFromDatabaseRowWithNullOptionalFields(): void
    {
        $row = [
            'id' => '00000000-0000-0000-0000-000000000001',
            'code' => 'TESTCODE12345678',
            'months_value' => 1,
            'valid_until' => '2026-12-31 23:59:59',
            'created_at' => '2026-01-01 10:00:00',
            'used_at' => null,
            'used_by_id' => null,
            'used_by_name' => null,
            'internal_note' => null,
        ];

        $voucher = VoucherOverview::fromDatabaseRow($row);

        self::assertNull($voucher->usedAt);
        self::assertNull($voucher->usedById);
        self::assertNull($voucher->usedByName);
        self::assertNull($voucher->internalNote);
    }
}
