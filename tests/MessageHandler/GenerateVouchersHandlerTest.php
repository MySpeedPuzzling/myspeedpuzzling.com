<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use SpeedPuzzling\Web\Entity\Voucher;
use SpeedPuzzling\Web\Message\GenerateVouchers;
use SpeedPuzzling\Web\Value\VoucherType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class GenerateVouchersHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
    }

    public function testGeneratesSingleVoucher(): void
    {
        $envelope = $this->messageBus->dispatch(
            new GenerateVouchers(
                count: 1,
                monthsValue: 3,
                validUntil: new DateTimeImmutable('+90 days'),
                codeLength: 12,
                internalNote: 'Test single voucher',
            ),
        );

        /** @var HandledStamp|null $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);
        self::assertNotNull($handledStamp);

        /** @var array<Voucher> $vouchers */
        $vouchers = $handledStamp->getResult();
        self::assertCount(1, $vouchers);

        $voucher = $vouchers[0];
        self::assertSame(12, strlen($voucher->code));
        self::assertSame(3, $voucher->monthsValue);
        self::assertSame('Test single voucher', $voucher->internalNote);
        self::assertFalse($voucher->isUsed());
    }

    public function testGeneratesMultipleVouchersWithUniqueCodes(): void
    {
        $envelope = $this->messageBus->dispatch(
            new GenerateVouchers(
                count: 5,
                monthsValue: 1,
                validUntil: new DateTimeImmutable('+30 days'),
                codeLength: 16,
                internalNote: 'Batch test',
            ),
        );

        /** @var HandledStamp|null $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);
        self::assertNotNull($handledStamp);

        /** @var array<Voucher> $vouchers */
        $vouchers = $handledStamp->getResult();
        self::assertCount(5, $vouchers);

        // Verify all codes are unique
        $codes = array_map(fn(Voucher $v) => $v->code, $vouchers);
        $uniqueCodes = array_unique($codes);
        self::assertCount(5, $uniqueCodes);

        // Verify all codes have correct length
        foreach ($vouchers as $voucher) {
            self::assertSame(16, strlen($voucher->code));
            self::assertSame(1, $voucher->monthsValue);
            self::assertSame('Batch test', $voucher->internalNote);
        }
    }

    public function testGeneratedCodesContainOnlyAllowedCharacters(): void
    {
        $envelope = $this->messageBus->dispatch(
            new GenerateVouchers(
                count: 10,
                monthsValue: 1,
                validUntil: new DateTimeImmutable('+30 days'),
                codeLength: 20,
            ),
        );

        /** @var HandledStamp|null $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);
        self::assertNotNull($handledStamp);

        /** @var array<Voucher> $vouchers */
        $vouchers = $handledStamp->getResult();

        $allowedChars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        foreach ($vouchers as $voucher) {
            $code = $voucher->code;
            for ($i = 0; $i < strlen($code); $i++) {
                self::assertStringContainsString(
                    $code[$i],
                    $allowedChars,
                    "Character '{$code[$i]}' at position $i in code '$code' is not in allowed characters",
                );
            }
        }
    }

    public function testVoucherValidUntilIsSetCorrectly(): void
    {
        $validUntil = new DateTimeImmutable('2026-12-31 23:59:59');

        $envelope = $this->messageBus->dispatch(
            new GenerateVouchers(
                count: 1,
                monthsValue: 6,
                validUntil: $validUntil,
            ),
        );

        /** @var HandledStamp|null $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);
        self::assertNotNull($handledStamp);

        /** @var array<Voucher> $vouchers */
        $vouchers = $handledStamp->getResult();

        self::assertSame($validUntil->format('Y-m-d H:i:s'), $vouchers[0]->validUntil->format('Y-m-d H:i:s'));
    }

    public function testGeneratesPercentageDiscountVoucher(): void
    {
        $envelope = $this->messageBus->dispatch(
            new GenerateVouchers(
                count: 1,
                monthsValue: null,
                validUntil: new DateTimeImmutable('+90 days'),
                codeLength: 12,
                internalNote: 'Test percentage voucher',
                voucherType: VoucherType::PercentageDiscount,
                percentageDiscount: 20,
                maxUses: 100,
            ),
        );

        /** @var HandledStamp|null $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);
        self::assertNotNull($handledStamp);

        /** @var array<Voucher> $vouchers */
        $vouchers = $handledStamp->getResult();
        self::assertCount(1, $vouchers);

        $voucher = $vouchers[0];
        self::assertSame(VoucherType::PercentageDiscount, $voucher->voucherType);
        self::assertSame(20, $voucher->percentageDiscount);
        self::assertSame(100, $voucher->maxUses);
        self::assertNull($voucher->monthsValue);
        self::assertSame('Test percentage voucher', $voucher->internalNote);
        self::assertTrue($voucher->isPercentageDiscount());
        self::assertFalse($voucher->isFreeMonths());
    }

    public function testGeneratesMultiplePercentageVouchers(): void
    {
        $envelope = $this->messageBus->dispatch(
            new GenerateVouchers(
                count: 3,
                monthsValue: null,
                validUntil: new DateTimeImmutable('+30 days'),
                codeLength: 16,
                internalNote: 'Batch percentage test',
                voucherType: VoucherType::PercentageDiscount,
                percentageDiscount: 15,
                maxUses: 50,
            ),
        );

        /** @var HandledStamp|null $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);
        self::assertNotNull($handledStamp);

        /** @var array<Voucher> $vouchers */
        $vouchers = $handledStamp->getResult();
        self::assertCount(3, $vouchers);

        foreach ($vouchers as $voucher) {
            self::assertSame(VoucherType::PercentageDiscount, $voucher->voucherType);
            self::assertSame(15, $voucher->percentageDiscount);
            self::assertSame(50, $voucher->maxUses);
        }
    }

    public function testDefaultVoucherTypeIsFreeMonths(): void
    {
        $envelope = $this->messageBus->dispatch(
            new GenerateVouchers(
                count: 1,
                monthsValue: 2,
                validUntil: new DateTimeImmutable('+30 days'),
            ),
        );

        /** @var HandledStamp|null $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);
        self::assertNotNull($handledStamp);

        /** @var array<Voucher> $vouchers */
        $vouchers = $handledStamp->getResult();

        $voucher = $vouchers[0];
        self::assertSame(VoucherType::FreeMonths, $voucher->voucherType);
        self::assertSame(2, $voucher->monthsValue);
        self::assertNull($voucher->percentageDiscount);
        self::assertSame(1, $voucher->maxUses);
        self::assertTrue($voucher->isFreeMonths());
    }
}
