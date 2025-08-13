<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Message\MarkNotificationsAsRead;
use SpeedPuzzling\Web\Query\GetNotifications;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class NotificationsController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetNotifications $getNotifications,
        readonly private MessageBusInterface $bus,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/notifikace',
            'en' => '/en/notifications',
        ],
        name: 'notifications',
    )]
    public function __invoke(#[CurrentUser] User $user): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('homepage');
        }

        $notifications = $this->getNotifications->forPlayer($player->playerId, 200);
        $notificationsCount = $this->getNotifications->countUnreadForPlayer($player->playerId);

        if ($notificationsCount > 0) {
            $this->bus->dispatch(
                new MarkNotificationsAsRead($player->playerId),
            );
        }

        return $this->render('notifications.html.twig', [
            'notifications' => $notifications,
            'notifications_count' => $notificationsCount,
        ]);
    }
}
