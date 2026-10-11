<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetAchievementMedal;
use SpeedPuzzling\Web\Query\GetBadgeCatalog;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Services\AchievementRarityProvider;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Value\AchievementRarityBand;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;
use SpeedPuzzling\Web\Value\ReturnUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The achievement detail sheet (docs/features/xp-levels/ux-polish/design-spec.md §2): the medal at 200 px that turns
 * over to its back (No. 0042 of 334, % of puzzlers, the earn date), the facts line, the five tiers as a list (tapping a
 * row shows that tier on the medal), "See who earned it (N)" and "All achievements". A bottom sheet on phones, the
 * centred modal on desktop - opted in by the content (achievement_sheet_controller.js), no other modal changes.
 *
 * Whose medal: `?player=` (a player's achievements page and showcase send it) shows that player's tiers, serials and
 * dates when their achievements are visible to the viewer by the rules of PlayerAchievementsController - a member, not
 * hidden by privacy (GetPlayerProfile's isPrivate, i.e. PrivateProfileAccess), not blocked either way (byId throws).
 * Otherwise the parameter is ignored silently and the sheet shows the viewer's own state (members) or the bare tiers.
 * The subject's medal wins for every viewer allowed to see it; a non-member viewer only loses their own ticks,
 * progress and AP (§8.3). The medal shown: the tapped tier (`?tier=`), else the highest tier of the player in context,
 * else Bronze. Tiers held at launch show "Held at launch" instead of a number and a date (§8.1, GetAchievementMedal).
 *
 * Rendered into the shared `modal-frame`; opened any other way the achievement's own page answers.
 */
final class AchievementInfoController extends AbstractController
{
    public function __construct(
        readonly private TranslatorInterface $translator,
        readonly private GetBadgeCatalog $getBadgeCatalog,
        readonly private GetAchievementMedal $getAchievementMedal,
        readonly private GetPlayerProfile $getPlayerProfile,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private XpFeatureGate $xpFeatureGate,
        readonly private AchievementRarityProvider $achievementRarityProvider,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/uspechy/{type}/info',
            'en' => '/en/achievements/{type}/info',
            'es' => '/es/logros/{type}/info',
            'ja' => '/ja/実績/{type}/info',
            'fr' => '/fr/succes/{type}/info',
            'de' => '/de/erfolge/{type}/info',
        ],
        name: 'achievement_info',
    )]
    public function __invoke(Request $request, string $type): Response
    {
        $viewer = $this->retrieveLoggedUserProfile->getProfile();

        if ($this->xpFeatureGate->isVisibleFor($viewer) === false) {
            throw $this->createNotFoundException();
        }

        $badgeType = BadgeType::tryFrom($type);

        if ($badgeType === null) {
            throw $this->createNotFoundException();
        }

        // The modal is a fragment for the shared modal-frame - opened any other way (a new tab, a crawler following
        // the badge's href) the achievement's own page answers instead
        if ($request->headers->get('Turbo-Frame') !== 'modal-frame') {
            $response = $this->redirectToRoute('achievement_detail', ['type' => $badgeType->value]);
            $response->setVary('Turbo-Frame');

            return $response;
        }

        $subject = $this->visibleSubject($request->query->getString('player'), $viewer);

        // Which tiers a player holds is a membership perk: the sheet is personal for a visible member's medal
        // (?player=) or for a member looking at their own; anybody else gets the bare tiers and a Bronze medal
        $contextPlayerId = $subject->playerId ?? ($viewer !== null && $viewer->activeMembership ? $viewer->playerId : null);
        $group = $badgeType->isTiered()
            ? $this->getBadgeCatalog->forPlayerAndType($contextPlayerId, $badgeType)
            : null;
        $medals = $contextPlayerId !== null && $group !== null
            ? $this->getAchievementMedal->forPlayerAndType($contextPlayerId, $badgeType)
            : [];

        $tier = $request->query->getString('tier');
        $tappedTier = $group !== null && ctype_digit($tier) ? BadgeTier::tryFrom((int) $tier) : null;
        $shownTier = $tappedTier ?? $group?->highestEarnedTier() ?? BadgeTier::Bronze;

        $rarity = $this->achievementRarityProvider->percentages()[$badgeType->value] ?? [];
        $holders = $this->achievementRarityProvider->holders()[$badgeType->value] ?? [];
        $bands = [];

        foreach (BadgeTier::cases() as $badgeTier) {
            $bands[$badgeTier->value] = AchievementRarityBand::fromPercent($rarity[$badgeTier->value] ?? 0.0);
        }

        // A single-tier achievement keeps its share under tier 0 (AchievementRarityProvider)
        $bands[0] = AchievementRarityBand::fromPercent($rarity[0] ?? 0.0);

        // The sheet's second button depends on where it was opened (Jan 2026-10-11): from a shelf (the catalogue, a
        // player's achievements page) the full list is the page behind it - no button. Elsewhere (a profile showcase,
        // the recap, your week): the shelf the medal belongs to - that player's, the viewer's own, else the catalogue.
        $fromShelf = $request->query->getString('from') === 'shelf';
        $moreLink = null;

        // Where the sheet was opened (docs/features/return-url.md): its buttons carry it on, so Back on the holders
        // page or on the achievements page goes there - your profile - and not to their logical parent
        $returnUrl = ReturnUrl::tryFrom($request->query->getString('return'));
        $returnQuery = $returnUrl === null ? [] : [
            'return' => $returnUrl->path,
            'return_title' => mb_substr(trim($request->query->getString('return_title')), 0, 80),
        ];

        if ($fromShelf === false) {
            if ($subject !== null && $subject->playerId !== $viewer?->playerId) {
                $moreLink = [
                    'url' => $this->generateUrl('player_achievements', ['playerId' => $subject->playerId] + $returnQuery),
                    'label' => $this->translator->trans('badges.medal.player_achievements', ['%name%' => $subject->playerName ?? ('#' . strtoupper($subject->code))]),
                ];
            } elseif ($viewer !== null && $viewer->activeMembership) {
                $moreLink = [
                    'url' => $this->generateUrl('player_achievements', ['playerId' => $viewer->playerId] + $returnQuery),
                    'label' => $this->translator->trans('badges.my_title'),
                ];
            } else {
                $moreLink = [
                    'url' => $this->generateUrl('badges_overview', $returnQuery),
                    'label' => $this->translator->trans('badges.medal.all_achievements'),
                ];
            }
        }

        $response = $this->render('_achievement_info_modal.html.twig', [
            'type' => $badgeType,
            'group' => $group,
            'shown_tier' => $shownTier,
            'personal' => $contextPlayerId !== null,
            'subject' => $subject,
            'more_link' => $moreLink,
            'return_query' => $returnQuery,
            'medals' => $medals,
            'logged_in' => $viewer !== null,
            'rarity' => $rarity,
            'bands' => $bands,
            'holders' => $holders,
            // Everybody who earned the achievement holds its Bronze (the evaluator fills the tiers below the one reached)
            'holders_total' => $holders[BadgeTier::Bronze->value] ?? 0,
            // A visitor sees no streak numbers of a player who opted out of streaks (design-spec §8.6)
            'show_record' => $subject === null || $badgeType !== BadgeType::Streak || $subject->streakOptedOut === false,
            // "Your achievements are kept and come back with membership" (design-spec §8.5)
            'viewer_lapsed' => $viewer !== null && $viewer->activeMembership === false && $viewer->membershipEndsAt !== null,
        ]);
        $response->setVary('Turbo-Frame');

        return $response;
    }

    /**
     * The player of `?player=` when their achievements are visible to the viewer - the rules of
     * PlayerAchievementsController (a member, not private for this viewer, not blocked); the viewer's own id means
     * their own sheet. Anything else: null, silently.
     */
    private function visibleSubject(string $playerId, null|PlayerProfile $viewer): null|PlayerProfile
    {
        if ($playerId === '' || $playerId === $viewer?->playerId) {
            return null;
        }

        try {
            $player = $this->getPlayerProfile->byId($playerId);
        } catch (PlayerNotFound) {
            return null;
        }

        if ($player->isPrivate || $player->activeMembership === false) {
            return null;
        }

        return $player;
    }
}
