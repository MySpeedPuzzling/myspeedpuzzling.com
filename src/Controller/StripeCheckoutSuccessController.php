<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\SubscribeMembership;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class StripeCheckoutSuccessController extends AbstractController
{
    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/uspesny-nakup-clenstvi/{sessionId}',
            'en' => '/en/membership-checkout-success/{sessionId}',
        ],
        name: 'stripe_checkout_success',
    )]
    public function __invoke(#[CurrentUser] UserInterface $user, string $sessionId): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('my_profile');
        }

        $this->messageBus->dispatch(
            new SubscribeMembership(
                $player->playerId,
                $sessionId,
            ),
        );

        $this->addFlash('success', 'flashes.membership_subscribed_successfully');

        return $this->redirectToRoute('membership');
    }
}
