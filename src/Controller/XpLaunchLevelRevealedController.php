<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\MarkAnnouncementModalSeen;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\AnnouncementModal;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The player put the last piece into their level medallion - in the launch modal, or on the reveal page
 * (docs/features/xp-levels/README.md §The launch reveal). POSTed once by the level's stage on the first tap
 * (last_piece_reveal_controller.js, fire-and-forget like the badge reveal).
 *
 * It is the launch modal's "seen" report: for this one modal "seen" means the level was revealed, not that the
 * modal opened (its level is tap-started, so the sound can play). The reveal page reads it to leave the level out of
 * the achievement deck. It can only confirm an impression that was claimed (MarkAnnouncementModalSeen); a report
 * that never arrives = the level card comes once more, nothing worse.
 */
final class XpLaunchLevelRevealedController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
    ) {
    }

    #[Route(path: '/-/xp-launch-level-revealed', name: 'xp_launch_level_revealed', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $this->messageBus->dispatch(new MarkAnnouncementModalSeen($profile->playerId, AnnouncementModal::XpLaunchReveal));

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
