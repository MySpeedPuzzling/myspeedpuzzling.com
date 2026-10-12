<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Component\Security\Core\User\UserInterface;
use SpeedPuzzling\Web\Message\MarkNotificationsAsRead;
use SpeedPuzzling\Web\Query\GetNotifications;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
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
            'es' => '/es/notificaciones',
            'ja' => '/ja/通知',
            'fr' => '/fr/notifications',
            'de' => '/de/benachrichtigungen',
        ],
        name: 'notifications',
    )]
    public function __invoke(#[CurrentUser] UserInterface $user): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('homepage');
        }

        $notifications = $this->getNotifications->forPlayer($player->playerId, 200);

        // The loaded rows tell whether anything is unread - no count of its own. Marked read synchronously, before
        // the layout renders, so the Inbox badge's one statement (InboxBadge) returns For you 0 here and the real
        // Messages part: unread messages stay visible on this page (docs/features/navigation INB-02, decision 48)
        foreach ($notifications as $notification) {
            if ($notification->readAt === null) {
                $this->bus->dispatch(
                    new MarkNotificationsAsRead($player->playerId),
                );

                break;
            }
        }

        return $this->render('notifications.html.twig', [
            'notifications' => $notifications,
        ]);
    }
}
