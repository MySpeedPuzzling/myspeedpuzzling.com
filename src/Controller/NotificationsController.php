<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Component\Security\Core\User\UserInterface;
use SpeedPuzzling\Web\Message\MarkNotificationsAsRead;
use SpeedPuzzling\Web\Navigation\InboxBadge;
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
        readonly private InboxBadge $inboxBadge,
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

        // The badge statement the layout runs anyway, run first (docs/features/navigation INB-02, decision 48): what the
        // badge counts is cleared on this page even when the list cannot show it - a count nothing here explains would
        // never go away (review F1). The loaded rows add the unread ones the badge does not count (message requests)
        $badge = $this->inboxBadge->forViewer();
        $notifications = $this->getNotifications->forPlayer($player->playerId, 200);
        $unread = $badge !== null && $badge->forYou > 0;

        foreach ($notifications as $notification) {
            if ($notification->readAt === null) {
                $unread = true;

                break;
            }
        }

        if ($unread === true) {
            // Every unread notification of the player, listed or not - synchronously, before the layout renders
            $this->bus->dispatch(
                new MarkNotificationsAsRead($player->playerId),
            );

            // The layout then shows For you 0 and the Messages part as read: unread messages stay visible on this
            // page, and the badge costs no second statement
            $this->inboxBadge->forYouMarkedRead();
        }

        return $this->render('notifications.html.twig', [
            'notifications' => $notifications,
            // Instant Back: marked read - the copies of the pages before (their badges) are stale
            'clears_snapshots' => $unread,
        ]);
    }
}
