<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetAllVouchers;
use SpeedPuzzling\Web\Tests\DataFixtures\VoucherFixture;
use SpeedPuzzling\Web\Value\VoucherType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetAllVouchersTest extends KernelTestCase
{
    private GetAllVouchers $getAllVouchers;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->getAllVouchers = $container->get(GetAllVouchers::class);
        $this->clock = $container->get(ClockInterface::class);
    }

    public function testCountByStatusReturnsCorrectCounts(): void
    {
        $now = $this->clock->now();
        $counts = $this->getAllVouchers->countByStatus($now);

        // Based on VoucherFixture: 1 available, 1 used, 1 expired
        self::assertGreaterThanOrEqual(1, $counts['available']);
        self::assertGreaterThanOrEqual(1, $counts['used']);
        self::assertGreaterThanOrEqual(1, $counts['expired']);
    }

    public function testAllAvailableReturnsOnlyAvailableVouchers(): void
    {
        $now = $this->clock->now();
        $vouchers = $this->getAllVouchers->allAvailable($now);

        foreach ($vouchers as $voucher) {
            self::assertFalse($voucher->isUsed(), 'Available voucher should not be used');
            self::assertFalse($voucher->isExpired($now), 'Available voucher should not be expired');
            self::assertTrue($voucher->isAvailable($now), 'Voucher should be available');
        }
    }

    public function testAllUsedReturnsOnlyUsedVouchers(): void
    {
        $vouchers = $this->getAllVouchers->allUsed();

        self::assertNotEmpty($vouchers, 'Should have at least one used voucher from fixtures');

        foreach ($vouchers as $voucher) {
            self::assertTrue($voucher->isUsed(), 'Used voucher should be marked as used');
            // For free months vouchers, usedAt should be set
            // For percentage discount vouchers, usedAt is null but they're considered used
            // when their claim count reaches maxUses
            if ($voucher->voucherType === VoucherType::FreeMonths) {
                self::assertNotNull($voucher->usedAt, 'Used free months voucher should have usedAt set');
            } else {
                self::assertGreaterThanOrEqual($voucher->maxUses, $voucher->usageCount, 'Used percentage voucher should have usage >= maxUses');
            }
        }
    }

    public function testAllExpiredReturnsOnlyExpiredVouchers(): void
    {
        $now = $this->clock->now();
        $vouchers = $this->getAllVouchers->allExpired($now);

        self::assertNotEmpty($vouchers, 'Should have at least one expired voucher from fixtures');

        foreach ($vouchers as $voucher) {
            self::assertFalse($voucher->isUsed(), 'Expired voucher should not be used');
            self::assertTrue($voucher->isExpired($now), 'Expired voucher should be expired');
        }
    }

    public function testAvailableVoucherHasMaskedCode(): void
    {
        $now = $this->clock->now();
        $vouchers = $this->getAllVouchers->allAvailable($now);

        $found = false;
        foreach ($vouchers as $voucher) {
            if ($voucher->code === VoucherFixture::VOUCHER_AVAILABLE_CODE) {
                $found = true;
                $maskedCode = $voucher->getMaskedCode();

                // Code TESTCODE12345678 should be masked as TEST**********78
                self::assertStringStartsWith('TEST', $maskedCode);
                self::assertStringEndsWith('78', $maskedCode);
                self::assertStringContainsString('*', $maskedCode);
                break;
            }
        }

        self::assertTrue($found, 'Should find the available voucher from fixtures');
    }

    public function testUsedVoucherHasPlayerInformation(): void
    {
        $vouchers = $this->getAllVouchers->allUsed();

        $found = false;
        foreach ($vouchers as $voucher) {
            if ($voucher->code === VoucherFixture::VOUCHER_USED_CODE) {
                $found = true;
                self::assertNotNull($voucher->usedById, 'Used voucher should have usedById');
                self::assertNotNull($voucher->usedByName, 'Used voucher should have usedByName');
                break;
            }
        }

        self::assertTrue($found, 'Should find the used voucher from fixtures');
    }
}
