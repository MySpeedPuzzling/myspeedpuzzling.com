<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Message\JoinReferralProgram;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class JoinReferralProgramController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/referral-program/pridat-se',
            'en' => '/en/referral-program/join',
            'es' => '/es/referral-program/unirse',
            'ja' => '/ja/referral-program/join',
            'fr' => '/fr/referral-program/rejoindre',
            'de' => '/de/referral-program/beitreten',
        ],
        name: 'join_referral_program',
        methods: ['POST'],
    )]
    public function __invoke(#[CurrentUser] User $user): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return $this->redirectToRoute('homepage');
        }

        $this->messageBus->dispatch(new JoinReferralProgram($profile->playerId));

        return $this->redirectToRoute('affiliate_dashboard');
    }
}
