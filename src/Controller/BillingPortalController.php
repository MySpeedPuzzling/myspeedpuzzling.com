<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Services\MembershipManagement;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class BillingPortalController extends AbstractController
{
    public function __construct(
        readonly private MembershipManagement $membershipManagement,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/clenstvi/platebni-portal',
            'en' => '/en/memberstip/billing-portal',
            'es' => '/es/membresia/portal-facturacion',
            'ja' => '/ja/メンバーシップ/支払いポータル',
        ],
        name: 'billing_portal',
    )]
    public function __invoke(#[CurrentUser] User $user): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('homepage');
        }

        $customerId = $player->stripeCustomerId;

        if ($customerId === null) {
            return $this->redirectToRoute('buy_membership');
        }

        $portalUrl = $this->membershipManagement->getBillingPortalUrl($customerId, $player->locale);

        return $this->redirect($portalUrl, 303);
    }
}
