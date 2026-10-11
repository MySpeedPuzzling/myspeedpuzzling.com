<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetAchievementPoints;
use SpeedPuzzling\Web\Query\GetBadgeCatalog;
use SpeedPuzzling\Web\Services\AchievementCatalogueRows;
use SpeedPuzzling\Web\Services\AchievementRarityProvider;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Services\Xp\XpLaunchReadiness;
use SpeedPuzzling\Web\Value\AchievementCatalogueFilter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The achievements catalogue (docs/features/xp-levels/launch-2026-10.md, layout: ux-polish/design-spec.md §1): the
 * summary line, "Closest next steps", the filter chips (All · Earned · Next up · Rarest) and the shelf of seals.
 *
 * Types and requirements are public; which ones a player holds is a membership perk - for anybody else the catalogue
 * is the same as for a guest (the catalogue is built without a player, so nothing personal is even read).
 */
final class BadgesOverviewController extends AbstractController
{
    /** Tiers earned this recently get the "New" dot (design-spec §1 "The cell") */
    public const int NEW_FOR_DAYS = 7;

    /** The order of the chips; "All" is the default (design-spec §1 "Filters") */
    public const array MEMBER_FILTERS = [
        AchievementCatalogueFilter::All,
        AchievementCatalogueFilter::Earned,
        AchievementCatalogueFilter::NextUp,
        AchievementCatalogueFilter::Rarest,
    ];

    public function __construct(
        readonly private GetBadgeCatalog $getBadgeCatalog,
        readonly private GetAchievementPoints $getAchievementPoints,
        readonly private AchievementRarityProvider $achievementRarityProvider,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private XpFeatureGate $xpFeatureGate,
        readonly private ClockInterface $clock,
        readonly private XpLaunchReadiness $xpLaunchReadiness,
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
    public function __invoke(Request $request): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($this->xpFeatureGate->isVisibleFor($profile) === false) {
            throw $this->createNotFoundException();
        }
        $memberId = $profile !== null && $profile->activeMembership ? $profile->playerId : null;
        $isMember = $memberId !== null;

        $catalog = $this->getBadgeCatalog->forPlayer($memberId);

        // A member's catalogue is about their own achievements: the unrevealed tiers are "?" seals leading to the
        // reveal, and tiers earned in the last 7 days (after launch) get the "New" dot - like on their own page
        $lists = AchievementCatalogueRows::build(
            $catalog,
            $this->achievementRarityProvider->percentages(),
            personal: $isMember,
            revealFlow: $isMember,
            newSince: $isMember ? $this->clock->now()->modify('-' . self::NEW_FOR_DAYS . ' days') : null,
            launchAt: $this->xpLaunchReadiness->readyAt(),
        );

        $filters = $isMember
            ? self::MEMBER_FILTERS
            : [AchievementCatalogueFilter::All, AchievementCatalogueFilter::Rarest];

        $filter = AchievementCatalogueFilter::tryFrom($request->query->getString('show'));

        if ($filter === null || in_array($filter, $filters, true) === false) {
            $filter = AchievementCatalogueFilter::All;
        }

        return $this->render('badges_overview.html.twig', [
            'lists' => $lists,
            'rows' => $lists->rows($filter),
            'filter' => $filter,
            'filters' => $filters,
            'filter_counts' => [
                AchievementCatalogueFilter::All->value => $lists->count(AchievementCatalogueFilter::All),
                AchievementCatalogueFilter::Earned->value => $lists->count(AchievementCatalogueFilter::Earned),
                AchievementCatalogueFilter::NextUp->value => $lists->count(AchievementCatalogueFilter::NextUp),
                AchievementCatalogueFilter::Rarest->value => $lists->count(AchievementCatalogueFilter::Rarest),
            ],
            'logged_in' => $profile !== null,
            'is_member' => $isMember,
            'ap_total' => $memberId !== null ? $this->getAchievementPoints->forPlayer($memberId) : null,
        ]);
    }
}
