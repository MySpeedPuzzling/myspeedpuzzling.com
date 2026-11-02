<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Membership;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\MembershipNotFound;
use SpeedPuzzling\Web\Exceptions\MembershipPlanSwitchNotPossible;
use SpeedPuzzling\Web\Query\GetPlayerMembership;
use SpeedPuzzling\Web\Value\MembershipPlanSwitchEligibility;
use Stripe\Price;
use Stripe\StripeClient;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Opens Stripe's own confirmation page for a switch between the monthly and the yearly plan
 * (docs/features/stripe.md "Switching plans"). We never change the subscription ourselves: Stripe shows the
 * amount, takes the payment and books a switch to monthly for the end of the paid year, all by the portal
 * configuration `myspeedpuzzling:stripe:sync-plan-switch-portal` keeps. The webhook tells us what happened.
 */
readonly final class MembershipPlanSwitching
{
    private const array LOCALES = ['cs', 'de', 'en', 'es', 'fr', 'ja'];

    public function __construct(
        private StripeClient $stripeClient,
        private GetPlayerMembership $getPlayerMembership,
        private UrlGeneratorInterface $router,
        private ClockInterface $clock,
        /** The portal configuration that allows the switch - empty = the feature is off */
        private string $portalConfigurationId,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->portalConfigurationId !== '';
    }

    /**
     * @throws MembershipPlanSwitchNotPossible
     */
    public function confirmationUrl(string $playerId, string $stripeCustomerId, null|string $locale): string
    {
        if (!$this->isAvailable()) {
            throw new MembershipPlanSwitchNotPossible();
        }

        try {
            $membership = $this->getPlayerMembership->byId($playerId);
        } catch (MembershipNotFound) {
            throw new MembershipPlanSwitchNotPossible();
        }

        $target = $membership->planSwitchTarget($this->clock->now());
        $subscriptionId = $membership->stripeSubscriptionId;

        if ($target === null || $subscriptionId === null) {
            throw new MembershipPlanSwitchNotPossible();
        }

        // Our copy only decides whether to ask - the live subscription decides whether to switch
        $subscription = $this->stripeClient->subscriptions->retrieve($subscriptionId);

        if (MembershipPlanSwitchEligibility::targetFor($subscription, $stripeCustomerId) !== $target) {
            throw new MembershipPlanSwitchNotPossible();
        }

        $targetPrice = $this->stripeClient->prices->all([
            'lookup_keys' => [$target->value],
            'active' => true,
            'limit' => 1,
        ])->data[0] ?? null;

        if (!$targetPrice instanceof Price) {
            throw new MembershipPlanSwitchNotPossible();
        }

        $item = $subscription->items->data[0];

        $session = $this->stripeClient->billingPortal->sessions->create([
            'customer' => $stripeCustomerId,
            'configuration' => $this->portalConfigurationId,
            // Every locale of ours is one of Stripe's
            'locale' => in_array($locale, self::LOCALES, true) ? $locale : 'auto',
            'return_url' => $this->router->generate('membership', ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL),
            'flow_data' => [
                'type' => 'subscription_update_confirm',
                'subscription_update_confirm' => [
                    'subscription' => $subscriptionId,
                    'items' => [[
                        'id' => $item->id,
                        'price' => $targetPrice->id,
                        'quantity' => 1,
                    ]],
                ],
                'after_completion' => [
                    'type' => 'redirect',
                    'redirect' => [
                        'return_url' => $this->router->generate('membership_plan_switched', ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL),
                    ],
                ],
            ],
        ]);

        return $session->url;
    }
}
