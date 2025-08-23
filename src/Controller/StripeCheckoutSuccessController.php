<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\UpdateMembershipSubscription;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Stripe\StripeClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
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
    ) {
    }

    #[Route(
        path: [
            'cs' => '/uspesny-nakup-clenstvi/{sessionId}',
            'en' => '/en/membership-checkout-success/{sessionId}',
            'es' => '/es/compra-membresia-exitosa/{sessionId}',
        ],
        name: 'stripe_checkout_success',
    )]
    public function __invoke(#[CurrentUser] UserInterface $user, string $sessionId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('my_profile');
        }

        $checkoutSession = $this->stripeClient->checkout->sessions->retrieve($sessionId);
        $subscriptionId = $checkoutSession->subscription;
        assert(is_string($subscriptionId));

        $this->messageBus->dispatch(
            new UpdateMembershipSubscription($subscriptionId),
        );

        $this->addFlash('success', $this->translator->trans('flashes.membership_subscribed_successfully'));

        return $this->redirectToRoute('membership');
    }
}
