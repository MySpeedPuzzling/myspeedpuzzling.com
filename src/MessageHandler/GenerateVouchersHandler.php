<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Voucher;
use SpeedPuzzling\Web\Exceptions\CouldNotGenerateUniqueCode;
use SpeedPuzzling\Web\Message\GenerateVouchers;
use SpeedPuzzling\Web\Repository\VoucherRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class GenerateVouchersHandler
{
    private const int MAX_CODE_GENERATION_ATTEMPTS = 100;

    public function __construct(
        private VoucherRepository $voucherRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CouldNotGenerateUniqueCode
     * @return array<string>
     */
    public function __invoke(GenerateVouchers $message): array
    {
        $generatedCodes = [];

        for ($i = 0; $i < $message->count; $i++) {
            $code = $this->generateUniqueCode($message->codeLength, $generatedCodes);
            $generatedCodes[] = $code;

            $voucher = new Voucher(
                id: Uuid::uuid7(),
                code: $code,
                monthsValue: $message->monthsValue,
                validUntil: $message->validUntil,
                createdAt: $this->clock->now(),
                internalNote: $message->internalNote,
                voucherType: $message->voucherType,
                percentageDiscount: $message->percentageDiscount,
                maxUses: $message->maxUses,
            );

            $this->voucherRepository->save($voucher);
        }

        return $generatedCodes;
    }

    /**
     * @param array<string> $alreadyGenerated
     * @throws CouldNotGenerateUniqueCode
     */
    private function generateUniqueCode(int $length, array $alreadyGenerated): string
    {
        for ($attempt = 0; $attempt < self::MAX_CODE_GENERATION_ATTEMPTS; $attempt++) {
            $code = $this->generateRandomCode($length);

            if (in_array($code, $alreadyGenerated, true)) {
                continue;
            }

            if ($this->voucherRepository->codeExists($code)) {
                continue;
            }

            return $code;
        }

        throw new CouldNotGenerateUniqueCode('Could not generate unique voucher code after ' . self::MAX_CODE_GENERATION_ATTEMPTS . ' attempts');
    }

    private function generateRandomCode(int $length): string
    {
        $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }

        return $code;
    }
}
