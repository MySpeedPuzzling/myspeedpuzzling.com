<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetAchievementPoints;
use SpeedPuzzling\Web\Query\GetBadgeCatalog;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BadgesOverviewController extends AbstractController
{
    public function __construct(
        readonly private GetBadgeCatalog $getBadgeCatalog,
        readonly private GetAchievementPoints $getAchievementPoints,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private XpFeatureGate $xpFeatureGate,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/uspechy',
            'en' => '/en/achievements',
            'es' => '/es/logros',
            'ja' => '/ja/実績',
            'fr' => '/fr/succes',
            'de' => '/de/erfolge',
        ],
        name: 'badges_overview',
    )]
    public function __invoke(): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($this->xpFeatureGate->isVisibleFor($profile) === false) {
            throw $this->createNotFoundException();
        }

        $playerId = $profile?->playerId;

        $catalog = $this->getBadgeCatalog->forPlayer($playerId);

        return $this->render('badges_overview.html.twig', [
            'catalog' => $catalog,
            'logged_in' => $playerId !== null,
            'is_member' => $profile?->activeMembership === true,
            'ap_total' => $profile !== null && $profile->activeMembership
                ? $this->getAchievementPoints->forPlayer($profile->playerId)
                : null,
        ]);
    }
}
