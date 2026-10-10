<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetAchievementPoints;
use SpeedPuzzling\Web\Query\GetBadgeCatalog;
use SpeedPuzzling\Web\Services\AchievementCatalogueRows;
use SpeedPuzzling\Web\Services\AchievementRarityProvider;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Value\AchievementCatalogueFilter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The achievements catalogue (docs/features/xp-levels/launch-2026-10.md): one row per achievement, a segmented
 * filter (Next up · Earned · All · Rarest), the requirements as text and the rarity of every tier.
 *
 * Types and requirements are public; which ones a player holds is a membership perk - for anybody else the catalogue
 * is the same as for a guest (the catalogue is built without a player, so nothing personal is even read).
 */
final class BadgesOverviewController extends AbstractController
{
    public function __construct(
        readonly private GetBadgeCatalog $getBadgeCatalog,
        readonly private GetAchievementPoints $getAchievementPoints,
        readonly private AchievementRarityProvider $achievementRarityProvider,
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
    public function __invoke(Request $request): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($this->xpFeatureGate->isVisibleFor($profile) === false) {
            throw $this->createNotFoundException();
        }
        $memberId = $profile !== null && $profile->activeMembership ? $profile->playerId : null;
        $isMember = $memberId !== null;

        $catalog = $this->getBadgeCatalog->forPlayer($memberId);

        $lists = AchievementCatalogueRows::build($catalog, $this->achievementRarityProvider->percentages(), personal: $isMember);
        $rows = $lists[AchievementCatalogueFilter::All->value];
        $nextUp = $lists[AchievementCatalogueFilter::NextUp->value];
        $earned = $lists[AchievementCatalogueFilter::Earned->value];
        $rarest = $lists[AchievementCatalogueFilter::Rarest->value];

        $filter = AchievementCatalogueFilter::tryFrom($request->query->getString('show'));

        if ($filter === null || ($filter->isPersonal() && $isMember === false)) {
            $filter = $isMember && $nextUp !== [] ? AchievementCatalogueFilter::NextUp : AchievementCatalogueFilter::All;
        }

        $filters = $isMember
            ? AchievementCatalogueFilter::cases()
            : [AchievementCatalogueFilter::All, AchievementCatalogueFilter::Rarest];

        return $this->render('badges_overview.html.twig', [
            'rows' => match ($filter) {
                AchievementCatalogueFilter::NextUp => $nextUp,
                AchievementCatalogueFilter::Earned => $earned,
                AchievementCatalogueFilter::All => $rows,
                AchievementCatalogueFilter::Rarest => $rarest,
            },
            'filter' => $filter,
            'filters' => $filters,
            'filter_counts' => [
                AchievementCatalogueFilter::NextUp->value => count($nextUp),
                AchievementCatalogueFilter::Earned->value => count($earned),
                AchievementCatalogueFilter::All->value => count($rows),
            ],
            'logged_in' => $profile !== null,
            'is_member' => $isMember,
            'ap_total' => $memberId !== null ? $this->getAchievementPoints->forPlayer($memberId) : null,
        ]);
    }
}
