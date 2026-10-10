<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetBadgeCatalog;
use SpeedPuzzling\Web\Query\GetBadges;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Services\AchievementCatalogueRows;
use SpeedPuzzling\Web\Services\AchievementRarityProvider;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Value\AchievementCatalogueFilter;
use SpeedPuzzling\Web\Value\BadgeType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A player's achievements - the "Achievements" tab of the player pages (docs/features/player-header.md): the
 * catalogue rows filled with that player's tiers and progress, Earned / Next up / All, AP and "N of 17 earned".
 *
 * Only members have achievements: a member's page is public (private profile and blocklist rules like every player
 * page), a non-member's page exists for its owner only (one line about membership), anybody else is sent to the
 * profile. Behind the launch switch (XpFeatureGate). Opting out of XP does not hide achievements - they are not XP
 * (the showcase on the profile follows the same rule).
 */
final class PlayerAchievementsController extends AbstractController
{
    private const array FILTERS = [
        AchievementCatalogueFilter::Earned,
        AchievementCatalogueFilter::NextUp,
        AchievementCatalogueFilter::All,
    ];

    public function __construct(
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private GetBadges $getBadges,
        readonly private GetBadgeCatalog $getBadgeCatalog,
        readonly private AchievementRarityProvider $achievementRarityProvider,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private XpFeatureGate $xpFeatureGate,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/uspechy-hrace/{playerId}',
            'en' => '/en/player-achievements/{playerId}',
            'es' => '/es/logros-jugador/{playerId}',
            'ja' => '/ja/プレイヤー実績/{playerId}',
            'fr' => '/fr/succes-joueur/{playerId}',
            'de' => '/de/spieler-erfolge/{playerId}',
        ],
        name: 'player_achievements',
    )]
    public function __invoke(Request $request, string $playerId): Response
    {
        $viewer = $this->retrieveLoggedUserProfile->getProfile();

        if ($this->xpFeatureGate->isVisibleFor($viewer) === false) {
            throw $this->createNotFoundException();
        }

        try {
            $player = $this->getPlayerProfile->byId($playerId);
        } catch (PlayerNotFound) {
            $this->addFlash('primary', $this->translator->trans('flashes.player_not_found'));

            return $this->redirectToRoute('players');
        }

        $isOwn = $viewer?->playerId === $player->playerId;

        if (($player->isPrivate && $isOwn === false) || ($player->activeMembership === false && $isOwn === false)) {
            return $this->redirectToRoute('player_profile', ['playerId' => $player->playerId]);
        }

        $lists = [];
        $earnedTypes = 0;
        $hasSupporter = false;

        if ($player->activeMembership) {
            // One read of the player's badge rows feeds both the catalogue groups and the single-tier ones
            $badges = $this->getBadges->allEarnedTiers($player->playerId);
            $catalog = $this->getBadgeCatalog->forPlayer($player->playerId, $badges);
            $lists = AchievementCatalogueRows::build($catalog, $this->achievementRarityProvider->percentages(), personal: true, revealFlow: $isOwn);

            $earnedTypes = count(array_unique(array_map(static fn ($badge): string => $badge->type->value, $badges)));
            // Early Adopter has a single tier granted by hand - not a catalogue row, listed with the earned ones
            $hasSupporter = array_filter($badges, static fn ($badge): bool => $badge->type === BadgeType::Supporter) !== [];
        }

        $filter = AchievementCatalogueFilter::tryFrom($request->query->getString('show'));

        if ($filter === null || in_array($filter, self::FILTERS, true) === false) {
            $filter = AchievementCatalogueFilter::Earned;
        }

        return $this->render('player_achievements.html.twig', [
            'player' => $player,
            'is_own' => $isOwn,
            'filter' => $filter,
            'filters' => self::FILTERS,
            'rows' => $lists[$filter->value] ?? [],
            'filter_counts' => array_map(static fn (array $rows): int => count($rows), $lists),
            'earned_types' => $earnedTypes,
            'total_types' => count(BadgeType::cases()),
            'has_supporter' => $hasSupporter,
        ]);
    }
}
