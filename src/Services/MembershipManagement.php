<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\MembershipNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerAlreadyHaveMembership;
use SpeedPuzzling\Web\Message\CreatePlayerStripeCustomer;
use SpeedPuzzling\Web\Query\GetPlayerMembership;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Value\BillingPeriod;
use Stripe\Price;
use Stripe\StripeClient;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

readonly final class MembershipManagement
{
    public function __construct(
        private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private RouterInterface $router,
        private StripeClient $stripeClient,
        private GetPlayerProfile $getPlayerProfile,
        private MessageBusInterface $messageBus,
        private GetPlayerMembership $getPlayerMembership,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PlayerAlreadyHaveMembership
     */
    public function getMembershipPaymentUrl(null|string $locale, BillingPeriod $period, null|string $priceLookupKey = null): string
    {
        if ($priceLookupKey === null) {
            $priceLookupKey = 'puzzlership_monthly';

            if ($period === BillingPeriod::Yearly) {
                $priceLookupKey = 'puzzlership_yearly';
            }
        }

        $prices = $this->stripeClient->prices->all([
            'lookup_keys' => [$priceLookupKey],
            'expand' => ['data.product']
        ]);

        $price = $prices->data[0] ?? null;
        assert($price instanceof Price);

        $successUrl = $this->router->generate(
            'stripe_checkout_success',
            parameters: [
                'sessionId' => 'CHECKOUT_SESSION_ID',
                '_locale' => $locale,
            ],
            referenceType: UrlGeneratorInterface::ABSOLUTE_URL
        );
        $successUrl = str_replace('CHECKOUT_SESSION_ID', '{CHECKOUT_SESSION_ID}', $successUrl);

        $cancelUrl = $this->router->generate(
            'membership',
            parameters: [
                '_locale' => $locale,
            ],
            referenceType: UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $userProfile = $this->retrieveLoggedUserProfile->getProfile();
        assert($userProfile !== null);
        $stripeCustomerId = $userProfile->stripeCustomerId ?? $this->createCustomerId($userProfile->playerId);

        $checkoutData = [
            'customer' => $stripeCustomerId,
            'mode' => 'subscription',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'line_items' => [[
                'price' => $price->id,
                'quantity' => 1,
            ]],
        ];

        try {
            $now = $this->clock->now();
            $membership = $this->getPlayerMembership->byId($userProfile->playerId);

            if (
                $membership->stripeSubscriptionId !== null
                && (
                    $membership->endsAt === null
                    || $now < $membership->endsAt
                )
            ) {
                throw new PlayerAlreadyHaveMembership;
            }

            if ($membership->endsAt !== null && $now < $membership->endsAt) {
                $daysBetweenTrialEnds = $now->diff($membership->endsAt)->days;

                if ($daysBetweenTrialEnds > 0) {
                    $checkoutData['subscription_data'] = [
                        'trial_settings' => [
                            'end_behavior' => [
                                'missing_payment_method' => 'pause',
                            ]
                        ],
                        'trial_period_days' => $daysBetweenTrialEnds,
                    ];
                }
            }
        } catch (MembershipNotFound) {
            // Do nothing, the membership does not exist, do not activate trial
        }

        $checkoutSession = $this->stripeClient->checkout->sessions->create($checkoutData);

        $checkoutUrl = $checkoutSession->url;
        assert($checkoutUrl !== null);

        return $checkoutUrl;
    }

    public function getBillingPortalUrl(string $stripeCustomerId, null|string $locale = null): string
    {
        $returnUrl = $this->router->generate(
            'membership',
            parameters: [
                '_locale' => $locale,
            ],
            referenceType: UrlGeneratorInterface::ABSOLUTE_URL
        );

        $session = $this->stripeClient->billingPortal->sessions->create([
            'customer' => $stripeCustomerId,
            'return_url' => $returnUrl,
        ]);

        return $session->url;
    }

    private function createCustomerId(string $playerId): string
    {
        $playerProfile = $this->getPlayerProfile->byId($playerId);

        if ($playerProfile->stripeCustomerId === null) {
            $this->messageBus->dispatch(
                new CreatePlayerStripeCustomer($playerId),
            );

            // Refetch after creation
            $playerProfile = $this->getPlayerProfile->byId($playerId);

            assert($playerProfile->stripeCustomerId !== null);
        }

        return $playerProfile->stripeCustomerId;
    }
}
