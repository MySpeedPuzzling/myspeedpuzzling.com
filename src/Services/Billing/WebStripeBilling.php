<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Billing;

use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\PlayerAlreadyHaveMembership;
use SpeedPuzzling\Web\Services\MembershipManagement;
use SpeedPuzzling\Web\Value\BillingPeriod;

readonly final class WebStripeBilling implements PlatformBillingInterface
{
    public function __construct(
        private MembershipManagement $membershipManagement,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getSubscriptionInitiation(Player $player, string $billingPeriod): array
    {
        $period = BillingPeriod::from($billingPeriod);

        try {
            $checkoutUrl = $this->membershipManagement->getMembershipPaymentUrl(null, $period);

            return [
                'type' => 'redirect',
                'url' => $checkoutUrl,
            ];
        } catch (PlayerAlreadyHaveMembership) {
            return [
                'type' => 'error',
                'message' => 'Player already has an active membership',
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function getManagementData(Player $player): array
    {
        if ($player->stripeCustomerId === null) {
            return [
                'type' => 'error',
                'message' => 'No Stripe customer found',
            ];
        }

        $portalUrl = $this->membershipManagement->getBillingPortalUrl($player->stripeCustomerId);

        return [
            'type' => 'redirect',
            'url' => $portalUrl,
            'instructions' => 'Manage your subscription in Stripe billing portal',
        ];
    }

    /**
     * @inheritDoc
     * Note: For web, purchases are verified via Stripe webhooks, not this method.
     */
    public function verifyAndActivate(Player $player, array $purchaseData): bool
    {
        // Web billing is handled entirely through Stripe webhooks
        // This method is not used for web platform
        return false;
    }
}
