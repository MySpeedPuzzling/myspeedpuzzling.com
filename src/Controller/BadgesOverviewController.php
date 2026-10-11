<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetBadgeCatalog;
use SpeedPuzzling\Web\Query\GetBadges;
use SpeedPuzzling\Web\Services\AchievementRarityProvider;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Value\AchievementCatalogueFilter;
use SpeedPuzzling\Web\Value\BadgeType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The achievements reference (Jan 2026-10-11): the same page for everybody - every achievement with all its tiers,
 * what each takes and how many puzzlers hold it, Early Adopter with how many hold it. Nothing personal on it: a
 * member's own achievements (the shelf, progress, next steps) live on their player page, which a member reaches
 * from the one line on top. This page is what only it does: the reference a guest, a free player or a search engine
 * can read, and the way into the holders lists.
 *
 * Statements: the catalogue (public, no player), the cached rarity aggregate; for a member one read of their badge
 * rows for the line on top.
 */
final class BadgesOverviewController extends AbstractController
{
    /** Tiers earned this recently get the "New" dot on a player's shelf (design-spec §1 "The cell") */
    public const int NEW_FOR_DAYS = 7;

    /** The order of the chips on a player's own achievements page; "All" is the default (design-spec §1 "Filters") */
    public const array MEMBER_FILTERS = [
        AchievementCatalogueFilter::All,
        AchievementCatalogueFilter::Earned,
        AchievementCatalogueFilter::NextUp,
        AchievementCatalogueFilter::Rarest,
    ];

    public function __construct(
        readonly private GetBadgeCatalog $getBadgeCatalog,
        readonly private GetBadges $getBadges,
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
    public function __invoke(): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($this->xpFeatureGate->isVisibleFor($profile) === false) {
            throw $this->createNotFoundException();
        }

        $isMember = $profile !== null && $profile->activeMembership;

        // The member's line on top: how many they hold (Early Adopter counts for its holders) - one read of their rows
        $own = null;

        if ($isMember) {
            $badges = $this->getBadges->allEarnedTiers($profile->playerId);
            $types = [];
            $tiers = 0;
            $hasSupporter = false;

            foreach ($badges as $badge) {
                if ($badge->type === BadgeType::Supporter) {
                    $hasSupporter = true;
                    continue;
                }

                $types[$badge->type->value] = true;
                $tiers++;
            }

            $tieredTypes = count(array_filter(BadgeType::cases(), static fn (BadgeType $type): bool => $type->isTiered()));
            $own = [
                'earned' => count($types) + ($hasSupporter ? 1 : 0),
                'total' => $tieredTypes + ($hasSupporter ? 1 : 0),
                'tiers' => $tiers,
                'tiers_total' => $tieredTypes * 5,
                'points' => $profile->achievementPoints,
            ];
        }

        return $this->render('badges_overview.html.twig', [
            // Built without a player: the public catalogue, nothing personal read
            'groups' => $this->getBadgeCatalog->forPlayer(null),
            'rarity' => $this->achievementRarityProvider->percentages(),
            'holders' => $this->achievementRarityProvider->holders(),
            'own' => $own,
            'logged_in' => $profile !== null,
            'is_member' => $isMember,
        ]);
    }
}
