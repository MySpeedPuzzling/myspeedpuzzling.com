<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\MarkAnnouncementModalSeen;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\AnnouncementModal;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The browser's report that an announcement modal really opened (docs/features/announcement-modals.md).
 * Counted, never relied on: it can only confirm an impression the server already claimed.
 */
final class MarkAnnouncementModalSeenController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
    ) {
    }

    #[Route(path: '/-/announcement-modal-seen', name: 'announcement_modal_seen', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();
        $modal = AnnouncementModal::tryFrom((string) $request->request->get('modal'));

        if ($profile === null || $modal === null) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $this->messageBus->dispatch(new MarkAnnouncementModalSeen($profile->playerId, $modal));

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
