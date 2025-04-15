<?php
declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Exceptions\PlayerAlreadyHaveMembership;
use SpeedPuzzling\Web\Services\MembershipManagement;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\BillingPeriod;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
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
            'cs' => '/koupit-clenstvi/{period}',
            'en' => '/en/buy-membership/{period}',
        ],
        name: 'buy_membership',
        defaults: ['period' => null],
    )]
    public function __invoke(#[CurrentUser] User $user, null|string $period, Request $request): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('homepage');
        }

        $billingPeriod = BillingPeriod::tryFrom($period ?? BillingPeriod::Monthly->value) ?? BillingPeriod::Monthly;

        /** @var null|string $priceLookupKey */
        $priceLookupKey = $request->query->get('priceLookupKey');

        try {
            $paymentUrl = $this->membershipManagement->getMembershipPaymentUrl($player->locale, $billingPeriod, $priceLookupKey);
        } catch (PlayerAlreadyHaveMembership) {
            return $this->redirectToRoute('billing_portal');
        }

        return $this->redirect($paymentUrl, 303);
    }
}
