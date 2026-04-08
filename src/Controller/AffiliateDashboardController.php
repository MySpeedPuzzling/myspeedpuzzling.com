<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Query\GetAffiliateDashboard;
use SpeedPuzzling\Web\Query\GetAffiliateSupporters;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class AffiliateDashboardController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetAffiliateDashboard $getAffiliateDashboard,
        readonly private GetAffiliateSupporters $getAffiliateSupporters,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/tribute-program',
            'en' => '/en/tribute-program',
            'es' => '/es/tribute-program',
            'ja' => '/ja/tribute-program',
            'fr' => '/fr/tribute-program',
            'de' => '/de/tribute-program',
        ],
        name: 'affiliate_dashboard',
    )]
    public function __invoke(#[CurrentUser] User $user): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return $this->redirectToRoute('homepage');
        }

        $affiliate = $this->getAffiliateDashboard->byPlayerId($profile->playerId);

        if ($affiliate === null) {
            return $this->render('affiliate_dashboard.html.twig', [
                'affiliate' => null,
                'supporters' => null,
                'referralUrl' => null,
            ]);
        }

        $supporters = $this->getAffiliateSupporters->byAffiliateId($affiliate->affiliateId);

        $referralUrl = $this->generateUrl(
            'homepage',
            ['ref' => $affiliate->code],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return $this->render('affiliate_dashboard.html.twig', [
            'affiliate' => $affiliate,
            'supporters' => $supporters,
            'referralUrl' => $referralUrl,
        ]);
    }
}
