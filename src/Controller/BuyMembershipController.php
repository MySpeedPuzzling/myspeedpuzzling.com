<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\EventSubscriber\ReferralCookieSubscriber;
use SpeedPuzzling\Web\Exceptions\AffiliateNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerAlreadyHaveMembership;
use SpeedPuzzling\Web\Repository\AffiliateRepository;
use SpeedPuzzling\Web\Services\MembershipManagement;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\BillingPeriod;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class BuyMembershipController extends AbstractController
{
    public function __construct(
        readonly private MembershipManagement $membershipManagement,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private AffiliateRepository $affiliateRepository,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/koupit-clenstvi/{period}',
            'en' => '/en/buy-membership/{period}',
            'es' => '/es/comprar-membresia/{period}',
            'ja' => '/ja/メンバーシップ購入/{period}',
            'fr' => '/fr/acheter-adhesion/{period}',
            'de' => '/de/mitgliedschaft-kaufen/{period}',
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

        $referralPlayerId = $this->resolveReferralPlayerId($request);

        try {
            $paymentUrl = $this->membershipManagement->getMembershipPaymentUrl($player->locale, $billingPeriod, $priceLookupKey, $referralPlayerId);
        } catch (PlayerAlreadyHaveMembership) {
            return $this->redirectToRoute('billing_portal');
        }

        return $this->redirect($paymentUrl, 303);
    }

    private function resolveReferralPlayerId(Request $request): null|string
    {
        $code = $request->getSession()->get('referral_code')
            ?? $request->cookies->get(ReferralCookieSubscriber::COOKIE_NAME);

        if (!is_string($code) || $code === '') {
            return null;
        }

        try {
            $affiliate = $this->affiliateRepository->getByCode($code);
        } catch (AffiliateNotFound) {
            return null;
        }

        if (!$affiliate->isActive()) {
            return null;
        }

        return $affiliate->player->id->toString();
    }
}
