<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Repository\VoucherClaimRepository;
use SpeedPuzzling\Web\Repository\VoucherRepository;
use SpeedPuzzling\Web\Services\GenerateVoucherImage;
use SpeedPuzzling\Web\Value\VoucherType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class VoucherImageController extends AbstractController
{
    public function __construct(
        private readonly VoucherRepository $voucherRepository,
        private readonly VoucherClaimRepository $voucherClaimRepository,
        private readonly GenerateVoucherImage $generateVoucherImage,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/voucher/{voucherId}/image-{variant}.png', name: 'voucher_image', requirements: ['variant' => '[1-4]'])]
    public function __invoke(string $voucherId, int $variant = 4): Response
    {
        $voucher = $this->voucherRepository->get($voucherId);

        $now = $this->clock->now();

        // Check if voucher is expired
        if ($voucher->isExpired($now)) {
            throw $this->createNotFoundException('Voucher has expired');
        }

        // Check availability based on voucher type
        if ($voucher->voucherType === VoucherType::FreeMonths) {
            if ($voucher->isUsed()) {
                throw $this->createNotFoundException('Voucher has already been used');
            }
        } else {
            // PercentageDiscount - check usage limit
            $usageCount = $this->voucherClaimRepository->countClaimsForVoucher($voucherId);
            if (!$voucher->hasRemainingUses($usageCount)) {
                throw $this->createNotFoundException('Voucher has reached its usage limit');
            }
        }

        $imageContent = $this->generateVoucherImage->generate($voucher, $variant);

        return new Response($imageContent, Response::HTTP_OK, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
