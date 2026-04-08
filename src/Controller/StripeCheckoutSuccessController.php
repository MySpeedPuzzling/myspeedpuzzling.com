<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\EventSubscriber\TributeReferralCookieSubscriber;
use SpeedPuzzling\Web\Message\AttributeTribute;
use SpeedPuzzling\Web\Message\UpdateMembershipSubscription;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Stripe\StripeClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

final class StripeCheckoutSuccessController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
        readonly private StripeClient $stripeClient,
        readonly private LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/uspesny-nakup-clenstvi/{sessionId}',
            'en' => '/en/membership-checkout-success/{sessionId}',
            'es' => '/es/compra-membresia-exitosa/{sessionId}',
            'ja' => '/ja/メンバーシップ購入成功/{sessionId}',
            'fr' => '/fr/adhesion-paiement-reussi/{sessionId}',
            'de' => '/de/mitgliedschaft-kauf-erfolgreich/{sessionId}',
        ],
        name: 'stripe_checkout_success',
    )]
    public function __invoke(#[CurrentUser] UserInterface $user, string $sessionId, Request $request): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('my_profile');
        }

        $checkoutSession = $this->stripeClient->checkout->sessions->retrieve($sessionId);
        $subscriptionId = $checkoutSession->subscription;
        assert(is_string($subscriptionId));

        try {
            $this->messageBus->dispatch(
                new UpdateMembershipSubscription($subscriptionId),
            );

            $this->addFlash('success', $this->translator->trans('flashes.membership_subscribed_successfully'));
        } catch (HandlerFailedException $e) {
            $this->logger->error('Stripe membership update failed after retries', [
                'subscription_id' => $subscriptionId,
                'error' => $e->getMessage(),
            ]);

            $this->addFlash('info', $this->translator->trans('flashes.membership_processing'));
        }

        // Attribute tribute from session (manual code entry) or cookie (referral link)
        $sessionTributeCode = $request->getSession()->get('tribute_code');
        $cookieTributeCode = $request->cookies->get(TributeReferralCookieSubscriber::COOKIE_NAME);

        if (is_string($sessionTributeCode) || is_string($cookieTributeCode)) {
            $this->messageBus->dispatch(
                new AttributeTribute(
                    subscriberPlayerId: $player->playerId,
                    sessionTributeCode: is_string($sessionTributeCode) ? $sessionTributeCode : null,
                    cookieTributeCode: is_string($cookieTributeCode) ? $cookieTributeCode : null,
                ),
            );

            $request->getSession()->remove('tribute_code');
        }

        $response = new RedirectResponse($this->generateUrl('membership'));

        // Clear the tribute referral cookie
        if ($request->cookies->has(TributeReferralCookieSubscriber::COOKIE_NAME)) {
            $response->headers->clearCookie(TributeReferralCookieSubscriber::COOKIE_NAME, '/');
        }

        return $response;
    }
}
