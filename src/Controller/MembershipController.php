<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\MembershipNotFound;
use SpeedPuzzling\Web\Query\GetPlayerMembership;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class MembershipController extends AbstractController
{
    public function __construct(
        readonly private GetPlayerMembership $getPlayerMembership,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private ClockInterface $clock,
        readonly private PlayerRepository $playerRepository,
    ) {
    }

     #[Route(
         path: [
            'cs' => '/clenstvi/',
            'en' => '/en/membership',
            'es' => '/es/membresia',
            'ja' => '/ja/メンバーシップ',
            'fr' => '/fr/adhesion',
            'de' => '/de/mitgliedschaft',
         ],
         name: 'membership',
     )]
    public function __invoke(#[CurrentUser] User $user, Request $request): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return $this->redirectToRoute('homepage');
        }

        $player = $this->playerRepository->get($profile->playerId);
        $now = $this->clock->now();

        try {
            $membership = $this->getPlayerMembership->byId($profile->playerId);
        } catch (MembershipNotFound) {
            $membership = null;
        }

        $isCzk = $request->getLocale() === 'cs';
        $baseMonthly = $isCzk ? 150 : 6;
        $baseYearly = $isCzk ? 1500 : 60;
        $decimals = $isCzk ? 0 : 2;

        $monthlyPrice = number_format($baseMonthly, $decimals, '.', '');
        $yearlyPrice = number_format($baseYearly, $decimals, '.', '');

        $discountedMonthlyPrice = null;
        $discountedYearlyPrice = null;

        if ($player->claimedDiscountVoucher !== null && !$player->claimedDiscountVoucher->isExpired($now)) {
            $discount = $player->claimedDiscountVoucher->percentageDiscount;

            $discountedMonthlyPrice = number_format($baseMonthly * (100 - $discount) / 100, $decimals, '.', '');
            $discountedYearlyPrice = number_format($baseYearly * (100 - $discount) / 100, $decimals, '.', '');
        }

        return $this->render('membership.html.twig', [
            'membership' => $membership,
            'player' => $player,
            'now' => $now,
            'monthlyPrice' => $monthlyPrice,
            'yearlyPrice' => $yearlyPrice,
            'discountedMonthlyPrice' => $discountedMonthlyPrice,
            'discountedYearlyPrice' => $discountedYearlyPrice,
        ]);
    }
}
