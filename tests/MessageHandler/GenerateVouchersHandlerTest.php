<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use SpeedPuzzling\Web\Message\GenerateVouchers;
use SpeedPuzzling\Web\Repository\VoucherRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class GenerateVouchersHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private VoucherRepository $voucherRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->voucherRepository = $container->get(VoucherRepository::class);
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

        /** @var array<string> $codes */
        $codes = $handledStamp->getResult();
        self::assertCount(1, $codes);

        $code = $codes[0];
        self::assertSame(12, strlen($code));

        $voucher = $this->voucherRepository->getByCode($code);
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

        /** @var array<string> $codes */
        $codes = $handledStamp->getResult();
        self::assertCount(5, $codes);

        // Verify all codes are unique
        $uniqueCodes = array_unique($codes);
        self::assertCount(5, $uniqueCodes);

        // Verify all codes have correct length
        foreach ($codes as $code) {
            self::assertSame(16, strlen($code));
        }

        // Verify all vouchers exist in database
        foreach ($codes as $code) {
            $voucher = $this->voucherRepository->getByCode($code);
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

        /** @var array<string> $codes */
        $codes = $handledStamp->getResult();

        $allowedChars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        foreach ($codes as $code) {
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

        /** @var array<string> $codes */
        $codes = $handledStamp->getResult();

        $voucher = $this->voucherRepository->getByCode($codes[0]);
        self::assertSame($validUntil->format('Y-m-d H:i:s'), $voucher->validUntil->format('Y-m-d H:i:s'));
    }
}
