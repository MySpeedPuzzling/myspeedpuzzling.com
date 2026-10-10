<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetAchievementHolders;
use SpeedPuzzling\Web\Query\GetBadges;
use SpeedPuzzling\Web\Services\CountryFilterOptions;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Value\BadgeTier;
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
        readonly private GetBadges $getBadges,
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

        // The hero is the viewer's own tier (members only - which tiers a player holds is a membership perk), else
        // the first one: the picture says "this is where you are", never a Diamond nobody asked about
        $heroTier = BadgeTier::Bronze;
        $viewerTier = null;

        if ($profile !== null && $profile->activeMembership) {
            foreach ($this->getBadges->forPlayer($profile->playerId) as $badge) {
                if ($badge->type === $badgeType && $badge->tier !== null) {
                    $viewerTier = $badge->tier;
                    $heroTier = $badge->tier;
                }
            }
        }

        return $this->render('achievement_detail.html.twig', [
            'type' => $badgeType,
            'hero_tier' => $heroTier,
            'viewer_tier' => $viewerTier,
            'tier_sections' => $this->getAchievementHolders->forType($badgeType, $country),
            'newest_earners' => $this->getAchievementHolders->newestEarners($badgeType),
            'countries' => CountryFilterOptions::fromCodes($this->getAchievementHolders->countries($badgeType), $request->getLocale()),
            'selected_country' => $country,
        ]);
    }
}
