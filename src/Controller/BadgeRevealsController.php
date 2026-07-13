<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetBadges;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Membership-activation reveal moment: every earned-but-unrevealed badge, flipped in
 * sequence. Reuses the first-click reveal endpoint per medallion.
 */
final class BadgeRevealsController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetBadges $getBadges,
        readonly private XpFeatureGate $xpFeatureGate,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/moje/odhaleni-uspechu',
            'en' => '/en/my/achievement-reveals',
            'es' => '/es/mis/logros-revelar',
            'ja' => '/ja/実績公開',
            'fr' => '/fr/mes/succes-reveler',
            'de' => '/de/meine/erfolge-aufdecken',
        ],
        name: 'badge_reveals',
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();
        assert($profile !== null);

        if ($this->xpFeatureGate->isVisibleFor($profile) === false) {
            throw $this->createNotFoundException();
        }

        // Achievement detail is a members-only surface — free players get the teaser instead.
        if ($profile->activeMembership === false) {
            return $this->redirectToRoute('membership');
        }

        return $this->render('badge_reveals.html.twig', [
            'badges' => $this->getBadges->unrevealedForPlayer($profile->playerId),
        ]);
    }
}
