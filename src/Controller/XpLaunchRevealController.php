<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Query\GetBadges;
use SpeedPuzzling\Web\Query\GetXpProfile;
use SpeedPuzzling\Web\Query\IsHintDismissed;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Value\HintType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * One-time launch reveal (§1.9): medallion assembly, XP counter spin, share CTA.
 * "Seen" state = DismissedHint row (no Player column, per plan decision) — the
 * continue button posts to the existing dismiss-hint endpoint.
 */
final class XpLaunchRevealController extends AbstractController
{
    public function __construct(
        readonly private GetXpProfile $getXpProfile,
        readonly private GetBadges $getBadges,
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
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
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

        return $this->render('xp_launch_reveal.html.twig', [
            'xp_profile' => $xpProfile,
            'badges_count' => count($this->getBadges->forPlayer($profile->playerId)),
            'is_member' => $profile->activeMembership,
            'player_id' => $profile->playerId,
        ]);
    }
}
