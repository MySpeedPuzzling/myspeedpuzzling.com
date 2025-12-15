<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Billing;

use SpeedPuzzling\Web\Entity\Player;

interface PlatformBillingInterface
{
    /**
     * Get subscription initiation data for the platform.
     * For web: returns checkout URL
     * For iOS/Android: returns product IDs and purchase flow data
     *
     * @return array<string, mixed>
     */
    public function getSubscriptionInitiation(Player $player, string $billingPeriod): array;

    /**
     * Get URL or data for managing existing subscription.
     * For web: returns Stripe billing portal URL
     * For iOS/Android: returns instructions to manage in app store
     *
     * @return array<string, mixed>
     */
    public function getManagementData(Player $player): array;

    /**
     * Verify and activate a purchase.
     * For web: handled by Stripe webhooks
     * For iOS: verify App Store receipt
     * For Android: verify Play Store purchase token
     *
     * @param array<string, mixed> $purchaseData
     */
    public function verifyAndActivate(Player $player, array $purchaseData): bool;
}
