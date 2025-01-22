<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Exceptions\PlayerAlreadyHaveMembership;
use SpeedPuzzling\Web\Services\MembershipManagement;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class BuyMembershipController extends AbstractController
{
    public function __construct(
        readonly private MembershipManagement $membershipManagement,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/koupit-clenstvi',
            'en' => '/en/buy-membership',
        ],
        name: 'buy_membership',
    )]
    public function __invoke(#[CurrentUser] User $user): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('homepage');
        }

        try {
            $paymentUrl = $this->membershipManagement->getMembershipPaymentUrl($player->locale);
        } catch (PlayerAlreadyHaveMembership) {
            return $this->redirectToRoute('billing_portal');
        }

        return $this->redirect($paymentUrl, 303);
    }
}
