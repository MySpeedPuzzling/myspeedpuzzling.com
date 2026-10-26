<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\MarkNotificationsAsRead;
use SpeedPuzzling\Web\Query\GetNotifications;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The bell's dropdown (notifications_dropdown_controller.js loads it when it opens): the newest notifications, then
 * everything is marked read - like opening the notifications page, which "Show all" leads to.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class NotificationsDropdownController extends AbstractController
{
    public const int SIZE = 8;

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetNotifications $getNotifications,
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(path: '/{_locale}/notifications-dropdown', name: 'notifications_dropdown', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        // Only the dropdown's frame asks for this; anybody else gets the page
        if ($player === null || $request->headers->get('Turbo-Frame') !== 'notifications-dropdown') {
            return $this->redirectToRoute('notifications');
        }

        $notifications = $this->getNotifications->forPlayer($player->playerId, self::SIZE);

        if ($this->getNotifications->countUnreadForPlayer($player->playerId) > 0) {
            $this->bus->dispatch(new MarkNotificationsAsRead($player->playerId));
        }

        $response = $this->render('notifications/_dropdown.html.twig', [
            'notifications' => $notifications,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->setVary('Turbo-Frame');

        return $response;
    }
}
