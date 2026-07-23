<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetAchievementHolders;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Value\BadgeType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Achievement holders directory: who earned each tier, first-to-earn highlight,
 * newest earners, country filter.
 */
final class AchievementDetailController extends AbstractController
{
    public function __construct(
        readonly private GetAchievementHolders $getAchievementHolders,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private XpFeatureGate $xpFeatureGate,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/uspechy/{type}',
            'en' => '/en/achievements/{type}',
            'es' => '/es/logros/{type}',
            'ja' => '/ja/実績/{type}',
            'fr' => '/fr/succes/{type}',
            'de' => '/de/erfolge/{type}',
        ],
        name: 'achievement_detail',
    )]
    public function __invoke(Request $request, string $type): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($this->xpFeatureGate->isVisibleFor($profile) === false) {
            throw $this->createNotFoundException();
        }

        $badgeType = BadgeType::tryFrom($type);

        if ($badgeType === null || $badgeType->isTiered() === false) {
            throw $this->createNotFoundException();
        }

        $country = $request->query->getString('country');
        $country = $country !== '' ? strtolower($country) : null;

        return $this->render('achievement_detail.html.twig', [
            'type' => $badgeType,
            'tier_sections' => $this->getAchievementHolders->forType($badgeType, $country),
            'newest_earners' => $this->getAchievementHolders->newestEarners($badgeType),
            'countries' => $this->getAchievementHolders->countries($badgeType),
            'selected_country' => $country,
        ]);
    }
}
