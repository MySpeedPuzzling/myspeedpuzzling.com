<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Billing;

use SpeedPuzzling\Web\Entity\Player;

/**
 * Stub implementation for App Store billing.
 * TODO: Implement actual receipt verification with App Store Server API.
 */
readonly final class IosAppStoreBilling implements PlatformBillingInterface
{
    private const string PRODUCT_ID_MONTHLY = 'com.myspeedpuzzling.premium.monthly';
    private const string PRODUCT_ID_YEARLY = 'com.myspeedpuzzling.premium.yearly';

    /**
     * @inheritDoc
     */
    public function getSubscriptionInitiation(Player $player, string $billingPeriod): array
    {
        $productId = $billingPeriod === 'yearly' ? self::PRODUCT_ID_YEARLY : self::PRODUCT_ID_MONTHLY;

        return [
            'type' => 'native_purchase',
            'platform' => 'ios',
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
            'platform' => 'ios',
            'instructions' => 'Manage your subscription in Settings > Apple ID > Subscriptions',
            'deepLink' => 'itms-apps://apps.apple.com/account/subscriptions',
        ];
    }

    /**
     * @inheritDoc
     * Expects purchaseData to contain 'receiptData' (base64 encoded receipt).
     */
    public function verifyAndActivate(Player $player, array $purchaseData): bool
    {
        // TODO: Implement App Store Server API v2 receipt verification
        // See: https://developer.apple.com/documentation/appstoreserverapi
        //
        // Steps:
        // 1. Decode the receipt data
        // 2. Call App Store Server API to verify the receipt
        // 3. Extract subscription status and expiration date
        // 4. Create or update membership for the player
        // 5. Set membership platform to 'ios'
        //
        // For now, this is a stub that always returns false

        return false;
    }
}
