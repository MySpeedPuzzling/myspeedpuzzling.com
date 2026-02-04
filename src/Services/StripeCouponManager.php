<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Voucher;
use Stripe\StripeClient;

readonly final class StripeCouponManager
{
    public function __construct(
        private StripeClient $stripeClient,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function getOrCreateCoupon(Voucher $voucher): string
    {
        if ($voucher->stripeCouponId !== null) {
            return $voucher->stripeCouponId;
        }

        assert($voucher->percentageDiscount !== null);

        $coupon = $this->stripeClient->coupons->create([
            'percent_off' => $voucher->percentageDiscount,
            'duration' => 'forever',
            'metadata' => [
                'voucher_id' => $voucher->id->toString(),
                'voucher_code' => $voucher->code,
            ],
        ]);

        $voucher->setStripeCouponId($coupon->id);
        $this->entityManager->flush();

        return $coupon->id;
    }
}
