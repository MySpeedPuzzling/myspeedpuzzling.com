<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetXpLaunchRevealBadges;
use SpeedPuzzling\Web\Query\GetXpProfile;
use SpeedPuzzling\Web\Query\IsHintDismissed;
use SpeedPuzzling\Web\Results\XpLaunchRevealBadge;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Value\HintType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * One-time launch reveal (§1.9). A member sees every achievement assemble as its own little puzzle, one by one in
 * crescendo order, while an XP counter grows from the XP of their puzzles by what each achievement brought; the level
 * medallion is the finale, a summary of everything ends it (last_piece_reveal + xp_launch_reveal Stimulus controllers).
 * Free players get the level medallion and their achievements locked - the achievement detail is a member feature.
 * "Seen" state = DismissedHint row (no Player column, per plan decision) — the continue button posts to the existing
 * dismiss-hint endpoint.
 */
final class XpLaunchRevealController extends AbstractController
{
    public function __construct(
        readonly private GetXpProfile $getXpProfile,
        readonly private GetXpLaunchRevealBadges $getXpLaunchRevealBadges,
        readonly private IsHintDismissed $isHintDismissed,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private XpFeatureGate $xpFeatureGate,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/moje/xp-odhaleni',
            'en' => '/en/my/xp-reveal',
            'es' => '/es/mi/xp-revelacion',
            'ja' => '/ja/マイ/xp公開',
            'fr' => '/fr/mon/xp-revelation',
            'de' => '/de/meine/xp-enthuellung',
        ],
        name: 'xp_launch_reveal',
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();
        assert($profile !== null);

        if ($this->xpFeatureGate->isVisibleFor($profile) === false) {
            throw $this->createNotFoundException();
        }

        $xpProfile = $this->getXpProfile->byPlayerId($profile->playerId);

        if ($xpProfile->optedOut || ($this->isHintDismissed)($profile->playerId, HintType::XpLaunchReveal)) {
            return $this->redirectToRoute('my_profile');
        }

        $badges = $this->getXpLaunchRevealBadges->forPlayer($profile->playerId);
        $badgesXp = array_sum(array_map(static fn (XpLaunchRevealBadge $badge): int => $badge->xp, $badges));

        return $this->render('xp_launch_reveal.html.twig', [
            'xp_profile' => $xpProfile,
            'badges' => $badges,
            // where the XP counter starts: what the puzzles alone brought (each achievement then adds its XP)
            'start_xp' => max(0, $xpProfile->xpTotal - $badgesXp),
            'is_member' => $profile->activeMembership,
            'player_id' => $profile->playerId,
        ]);
    }
}
