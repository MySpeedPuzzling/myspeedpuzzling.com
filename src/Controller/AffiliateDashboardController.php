<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Message\JoinReferralProgram;
use SpeedPuzzling\Web\Query\GetAffiliateSupporters;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class AffiliateDashboardController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetAffiliateSupporters $getAffiliateSupporters,
        readonly private MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/referral-program',
            'en' => '/en/referral-program',
            'es' => '/es/referral-program',
            'ja' => '/ja/referral-program',
            'fr' => '/fr/referral-program',
            'de' => '/de/referral-program',
        ],
        name: 'affiliate_dashboard',
    )]
    public function __invoke(#[CurrentUser] User $user, Request $request): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return $this->redirectToRoute('homepage');
        }

        // Handle join POST
        if ($request->isMethod('POST')) {
            $this->messageBus->dispatch(new JoinReferralProgram($profile->playerId));

            return $this->redirectToRoute('affiliate_dashboard');
        }

        return $this->render('affiliate_dashboard.html.twig', [
            'supporters' => $profile->referralProgramJoinedAt !== null
                ? $this->getAffiliateSupporters->byPlayerId($profile->playerId)
                : null,
            'referralUrl' => $profile->referralProgramJoinedAt !== null
                ? $this->generateUrl('homepage_crossroads', ['ref' => $profile->code], UrlGeneratorInterface::ABSOLUTE_URL)
                : null,
        ]);
    }
}
