<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetBadgeCatalog;
use SpeedPuzzling\Web\Query\GetBadges;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Services\AchievementCatalogueRows;
use SpeedPuzzling\Web\Services\AchievementRarityProvider;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Services\Xp\XpLaunchReadiness;
use SpeedPuzzling\Web\Value\AchievementCatalogueFilter;
use SpeedPuzzling\Web\Value\BadgeType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A player's achievements - the "Achievements" tab of the player pages (docs/features/player-header.md): the same
 * layout as the catalogue (docs/features/xp-levels/ux-polish/design-spec.md §1) filled with that player's tiers and
 * progress - summary "13 of 16 achievements · 41 of 80 tiers" (tiered only), the closest next steps (the owner only,
 * design-spec §8.6: a visitor came to see what someone has), All / Earned / Next up / Rarest first and the shelf.
 *
 * Only members have achievements: a member's page is public (private profile and blocklist rules like every player
 * page), a non-member's page exists for its owner only (one line about membership), anybody else is sent to the
 * profile. Behind the launch switch (XpFeatureGate). Opting out of XP does not hide achievements - they are not XP
 * (the showcase on the profile follows the same rule).
 */
final class PlayerAchievementsController extends AbstractController
{
    public const array VISITOR_FILTERS = [
        AchievementCatalogueFilter::All,
        AchievementCatalogueFilter::Earned,
        AchievementCatalogueFilter::Rarest,
    ];

    public function __construct(
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private GetBadges $getBadges,
        readonly private GetBadgeCatalog $getBadgeCatalog,
        readonly private AchievementRarityProvider $achievementRarityProvider,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private XpFeatureGate $xpFeatureGate,
        readonly private TranslatorInterface $translator,
        readonly private ClockInterface $clock,
        readonly private XpLaunchReadiness $xpLaunchReadiness,
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

        $lists = null;
        $hasSupporter = false;

        if ($player->activeMembership) {
            // One read of the player's badge rows feeds both the catalogue groups and the single-tier ones
            $badges = $this->getBadges->allEarnedTiers($player->playerId);
            $catalog = $this->getBadgeCatalog->forPlayer($player->playerId, $badges);
            $lists = AchievementCatalogueRows::build(
                $catalog,
                $this->achievementRarityProvider->percentages(),
                personal: true,
                revealFlow: $isOwn,
                newSince: $isOwn ? $this->clock->now()->modify('-' . BadgesOverviewController::NEW_FOR_DAYS . ' days') : null,
                launchAt: $this->xpLaunchReadiness->readyAt(),
                // A visitor came to see what the player has, not their unfinished progress (design-spec §8.6, the
                // sheet does the same) - which also keeps the streak of an owner who switched streaks off private
                hideProgressOf: $isOwn ? [] : BadgeType::cases(),
            );

            // Early Adopter has a single tier granted by hand - not a catalogue row, shelved with the earned ones
            $hasSupporter = array_filter($badges, static fn ($badge): bool => $badge->type === BadgeType::Supporter) !== [];
        }

        // "Next up" is about progress, which only the owner sees
        $filters = $isOwn ? BadgesOverviewController::MEMBER_FILTERS : self::VISITOR_FILTERS;
        $filter = AchievementCatalogueFilter::tryFrom($request->query->getString('show'));

        if ($filter === null || in_array($filter, $filters, true) === false) {
            $filter = AchievementCatalogueFilter::All;
        }

        return $this->render('player_achievements.html.twig', [
            'player' => $player,
            'is_own' => $isOwn,
            'filter' => $filter,
            'filters' => $filters,
            'lists' => $lists,
            'rows' => $lists?->rows($filter) ?? [],
            'filter_counts' => [
                AchievementCatalogueFilter::All->value => ($lists?->count(AchievementCatalogueFilter::All) ?? 0) + ($hasSupporter ? 1 : 0),
                AchievementCatalogueFilter::Earned->value => ($lists?->count(AchievementCatalogueFilter::Earned) ?? 0) + ($hasSupporter ? 1 : 0),
                AchievementCatalogueFilter::NextUp->value => $lists?->count(AchievementCatalogueFilter::NextUp) ?? 0,
                AchievementCatalogueFilter::Rarest->value => $lists?->count(AchievementCatalogueFilter::Rarest) ?? 0,
            ],
            // The single-tier Early Adopter sits with the earned ones (All, Earned) - it has no progress nor rarity
            'show_supporter' => $hasSupporter && in_array($filter, [AchievementCatalogueFilter::All, AchievementCatalogueFilter::Earned], true),
        ]);
    }
}
