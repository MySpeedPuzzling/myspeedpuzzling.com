<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Message\CreatePlayerStripeCustomer;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use Stripe\Price;
use Stripe\StripeClient;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

readonly final class MembershipManagement
{
    private const string PRICE_LOOKUP_KEY = 'puzzlership_monthly_promo';

    public function __construct(
        private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private RouterInterface $router,
        private StripeClient $stripeClient,
        private GetPlayerProfile $getPlayerProfile,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function getMembershipPaymentUrl(null|string $locale): string
    {
        // Currently we have only one subscription plan so it is okay to have it hardcoded
        $priceLookupKey = self::PRICE_LOOKUP_KEY;

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
            'stripe_checkout_cancel',
            parameters: [
                '_locale' => $locale,
            ],
            referenceType: UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $userProfile = $this->retrieveLoggedUserProfile->getProfile();
        assert($userProfile !== null);
        $stripeCustomerId = $userProfile->stripeCustomerId ?? $this->createCustomerId($userProfile->playerId);

        $checkoutSession = $this->stripeClient->checkout->sessions->create([
            'customer' => $stripeCustomerId,
            'mode' => 'subscription',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'line_items' => [[
                'price' => $price->id,
                'quantity' => 1,
            ]],
        ]);

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
