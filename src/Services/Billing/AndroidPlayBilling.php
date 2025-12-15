<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Billing;

use SpeedPuzzling\Web\Entity\Player;

/**
 * Stub implementation for Google Play billing.
 * TODO: Implement actual purchase verification with Google Play Developer API.
 */
readonly final class AndroidPlayBilling implements PlatformBillingInterface
{
    private const string PRODUCT_ID_MONTHLY = 'premium_monthly';
    private const string PRODUCT_ID_YEARLY = 'premium_yearly';

    /**
     * @inheritDoc
     */
    public function getSubscriptionInitiation(Player $player, string $billingPeriod): array
    {
        $productId = $billingPeriod === 'yearly' ? self::PRODUCT_ID_YEARLY : self::PRODUCT_ID_MONTHLY;

        return [
            'type' => 'native_purchase',
            'platform' => 'android',
            'productId' => $productId,
            'availableProducts' => [
                [
                    'id' => self::PRODUCT_ID_MONTHLY,
                    'period' => 'monthly',
                ],
                [
                    'id' => self::PRODUCT_ID_YEARLY,
                    'period' => 'yearly',
                ],
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function getManagementData(Player $player): array
    {
        return [
            'type' => 'native_management',
            'platform' => 'android',
            'instructions' => 'Manage your subscription in Google Play Store > Payments & subscriptions',
            'deepLink' => 'https://play.google.com/store/account/subscriptions',
        ];
    }

    /**
     * @inheritDoc
     * Expects purchaseData to contain 'purchaseToken' and 'productId'.
     */
    public function verifyAndActivate(Player $player, array $purchaseData): bool
    {
        // TODO: Implement Google Play Developer API purchase verification
        // See: https://developers.google.com/android-publisher/api-ref/rest/v3/purchases.subscriptions
        //
        // Steps:
        // 1. Extract purchaseToken and productId from purchaseData
        // 2. Call Google Play Developer API to verify the purchase
        // 3. Extract subscription status and expiration date
        // 4. Create or update membership for the player
        // 5. Set membership platform to 'android'
        //
        // For now, this is a stub that always returns false

        return false;
    }
}
