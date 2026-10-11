<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\ClaimAnnouncementModalImpression;
use SpeedPuzzling\Web\Query\GetAnnouncementModalSeen;
use SpeedPuzzling\Web\Query\GetXpLaunchRevealBadges;
use SpeedPuzzling\Web\Query\GetXpProfile;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\Xp\XpFeatureGate;
use SpeedPuzzling\Web\Services\Xp\XpLaunchReadiness;
use SpeedPuzzling\Web\Value\AnnouncementModal;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The launch reveal (§1.9, docs/features/xp-levels/launch-2026-10.md, README §The launch reveal). The launch modal is
 * the level's chapter; this page is the achievements' chapter: a member sees every achievement assemble as its own
 * little puzzle, one by one in crescendo order, with what it took, how many puzzlers hold it, the next tier and the
 * Achievement Points it brought; a summary of everything ends it (last_piece_reveal + xp_launch_reveal Stimulus
 * controllers). The level card leads the deck only when the level was not revealed yet (the modal never opened, or it
 * was closed before the last piece): `level_revealed` = the launch modal's "seen" report, which for this modal is sent
 * when the level is revealed (XpLaunchLevelRevealedController). The XP shown is the player's XP from puzzles -
 * achievements grant none. Free players get their level (to reveal, or as a summary once revealed) and a count of
 * their waiting achievements, never which ones (the achievement detail is a member feature).
 *
 * `?start=1` (the launch modal's "Reveal my achievements", a tap that was already the invitation) opens straight on the
 * first card: no intro with a second button to the same thing (Jan 2026-10-12).
 *
 * One URL for every way in - the launch newsletter, the announcement modal, a bookmark: a guest is sent through the
 * sign-in and back (LoginEntryPoint), and the page stays viewable after it was seen (a replay is harmless, the
 * newsletter link must never dead-end). A player who switched the experience system off goes to their profile, and
 * so does everybody but admins before the launch switch is on (XpLaunchReadiness - the history is still being
 * calculated). Opening the page after the launch uses the launch announcement modal up, so the modal never offers a
 * reveal already seen.
 */
final class XpLaunchRevealController extends AbstractController
{
    public function __construct(
        readonly private GetXpProfile $getXpProfile,
        readonly private GetXpLaunchRevealBadges $getXpLaunchRevealBadges,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private XpFeatureGate $xpFeatureGate,
        readonly private XpLaunchReadiness $xpLaunchReadiness,
        readonly private GetAnnouncementModalSeen $getAnnouncementModalSeen,
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
    public function __invoke(Request $request): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();
        assert($profile !== null);

        if ($this->xpFeatureGate->isVisibleFor($profile) === false) {
            return $this->redirectToRoute('my_profile');
        }

        $xpProfile = $this->getXpProfile->byPlayerId($profile->playerId);

        if ($xpProfile->optedOut) {
            return $this->redirectToRoute('my_profile');
        }

        // An admin looking before the launch does not use their own launch modal up
        if ($this->xpLaunchReadiness->isReady() && isset($profile->modalImpressions[AnnouncementModal::XpLaunchReveal->value]) === false) {
            $this->messageBus->dispatch(new ClaimAnnouncementModalImpression($profile->playerId, AnnouncementModal::XpLaunchReveal));
        }

        // The facts (progress, holders) only for the deck - a free player learns how many, never which
        $badges = $profile->activeMembership
            ? $this->getXpLaunchRevealBadges->withFacts($profile->playerId)
            : $this->getXpLaunchRevealBadges->forPlayer($profile->playerId);

        return $this->render('xp_launch_reveal.html.twig', [
            'xp_profile' => $xpProfile,
            'badges' => $badges,
            'is_member' => $profile->activeMembership,
            'player_id' => $profile->playerId,
            'level_revealed' => $this->getAnnouncementModalSeen->isSeen($profile->playerId, AnnouncementModal::XpLaunchReveal),
            'autostart' => $request->query->getBoolean('start'),
        ]);
    }
}
