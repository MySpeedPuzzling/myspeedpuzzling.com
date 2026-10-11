<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetAchievementHolders;
use SpeedPuzzling\Web\Query\GetAchievementMedal;
use SpeedPuzzling\Web\Services\CountryFilterOptions;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Services\Xp\XpLaunchReadiness;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;
use SpeedPuzzling\Web\Value\HeldAtLaunch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Achievement holders directory (docs/features/xp-levels/ux-polish/design-spec.md §1 "Holders page", §8.2): the
 * hero with the member viewer's tier and medal number ("You hold Gold · No. 80 of 82", or "held at launch"), the
 * 5-seal ladder with holder counts, the newest holders of each tier as rows with their date ("at launch" for the
 * launch backfill's), the country filter. A constant number of statements whatever the number of holders (pinned by
 * a budget test).
 */
final class AchievementDetailController extends AbstractController
{
    public function __construct(
        readonly private GetAchievementHolders $getAchievementHolders,
        readonly private GetAchievementMedal $getAchievementMedal,
        readonly private XpLaunchReadiness $xpLaunchReadiness,
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

        $launchAt = $this->xpLaunchReadiness->readyAt();
        $sections = $this->getAchievementHolders->forType($badgeType, $country, $launchAt);

        // The hero is the viewer's own highest tier (members only - which tiers a player holds is a membership perk),
        // else Bronze: the picture says "this is where you are", never a Diamond nobody asked about. Its number is
        // the medal's (GetAchievementMedal - the one serial rule of design-spec §2, §8.1)
        $viewerMedal = null;

        if ($profile !== null && $profile->activeMembership) {
            $medals = $this->getAchievementMedal->forPlayerAndType($profile->playerId, $badgeType);
            $viewerMedal = $medals === [] ? null : $medals[max(array_keys($medals))];
        }

        $viewerTotal = 0;

        foreach ($sections as $section) {
            if ($viewerMedal !== null && $section->tier === $viewerMedal->tier) {
                // "No. 335 of 334" can't happen: a holder counted since the counts were read still fits
                $viewerTotal = max($section->allCount, $viewerMedal->serial);
            }
        }

        return $this->render('achievement_detail.html.twig', [
            'type' => $badgeType,
            'hero_tier' => $viewerMedal->tier ?? BadgeTier::Bronze,
            'viewer_medal' => $viewerMedal,
            'viewer_held_at_launch' => $viewerMedal !== null && HeldAtLaunch::is($viewerMedal->earnedAt, $launchAt),
            'viewer_total' => $viewerTotal,
            'tier_sections' => $sections,
            'countries' => CountryFilterOptions::fromCodes($this->getAchievementHolders->countries($badgeType), $request->getLocale()),
            'selected_country' => $country,
        ]);
    }
}
