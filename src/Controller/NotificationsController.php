<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Symfony\Component\HttpFoundation\Request;
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
    public const int PAGE_SIZE = 30;

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
    public function __invoke(#[CurrentUser] UserInterface $user, Request $request): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('homepage');
        }

        $offset = max(0, $request->query->getInt('offset'));
        // One row more than the page tells whether "Show more" has anything to show
        $notifications = $this->getNotifications->forPlayer($player->playerId, self::PAGE_SIZE + 1, $offset);
        $nextOffset = count($notifications) > self::PAGE_SIZE ? $offset + self::PAGE_SIZE : null;
        $parameters = [
            'notifications' => array_slice($notifications, 0, self::PAGE_SIZE),
            'position_offset' => $offset,
            'next_offset' => $nextOffset,
        ];

        // "Show more": the next page into its frame - the first page has marked everything read already
        if ($offset > 0 && $request->headers->get('Turbo-Frame') === "notifications-from-{$offset}") {
            return $this->render('notifications/_more.html.twig', $parameters);
        }

        $this->markAllRead($player->playerId);

        return $this->render('notifications.html.twig', $parameters + [
            // Everything unread was just marked read (handled synchronously), so the layout's
            // bell shows 0 - without counting the player's notifications a second time
            'notifications_count' => 0,
        ]);
    }

    private function markAllRead(string $playerId): void
    {
        if ($this->getNotifications->countUnreadForPlayer($playerId) > 0) {
            $this->bus->dispatch(new MarkNotificationsAsRead($playerId));
        }
    }
}
