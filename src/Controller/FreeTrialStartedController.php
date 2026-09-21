<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\MembershipNotFound;
use SpeedPuzzling\Web\Query\GetPlayerMembership;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Where a just started trial lands: what is unlocked, until when, and where to look first.
 */
final class FreeTrialStartedController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetPlayerMembership $getPlayerMembership,
        readonly private ClockInterface $clock,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/clenstvi/zkusebni-obdobi',
            'en' => '/en/membership/free-trial',
            'es' => '/es/membresia/periodo-de-prueba',
            'ja' => '/ja/メンバーシップ/トライアル',
            'fr' => '/fr/adhesion/periode-d-essai',
            'de' => '/de/mitgliedschaft/testzeitraum',
        ],
        name: 'free_trial_started',
        methods: ['GET'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return $this->redirectToRoute('homepage');
        }

        $now = $this->clock->now();

        try {
            $membership = $this->getPlayerMembership->byId($profile->playerId);
        } catch (MembershipNotFound) {
            return $this->redirectToRoute('membership');
        }

        if ($membership->isInFreeTrial($now) === false) {
            return $this->redirectToRoute('membership');
        }

        return $this->render('free_trial_started.html.twig', [
            'membership' => $membership,
            'player' => $profile,
            'now' => $now,
        ]);
    }
}
