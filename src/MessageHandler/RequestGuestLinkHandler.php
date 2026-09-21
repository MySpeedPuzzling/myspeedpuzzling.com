<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\GuestLinkRequest;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Exceptions\GuestLinkNotPossible;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\RequestGuestLink;
use SpeedPuzzling\Web\Query\GetUserBlocks;
use SpeedPuzzling\Web\Repository\GuestLinkRequestRepository;
use SpeedPuzzling\Web\Repository\NotificationRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\NotificationType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RequestGuestLinkHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private GuestLinkRequestRepository $guestLinkRequestRepository,
        private NotificationRepository $notificationRepository,
        private GetUserBlocks $getUserBlocks,
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PlayerNotFound the code names nobody
     * @throws GuestLinkNotPossible
     */
    public function __invoke(RequestGuestLink $message): void
    {
        $requester = $this->playerRepository->get($message->requesterPlayerId);
        $target = $this->playerRepository->getByCode(trim($message->targetPlayerCode, "# \t\n\r\0"));

        if ($target->id->equals($requester->id)) {
            throw new GuestLinkNotPossible();
        }

        // The guest as the requester's own pairs/teams know them - nobody can ask about somebody else's guest
        $guestName = $this->connection->fetchOne(
            <<<SQL
SELECT guest.guest_name
FROM puzzling_team_member guest
INNER JOIN puzzling_team_member me ON me.team_id = guest.team_id AND me.player_id = :playerId
WHERE guest.member_key = :guestKey AND guest.player_id IS NULL
LIMIT 1
SQL,
            ['playerId' => $message->requesterPlayerId, 'guestKey' => $message->guestKey],
        );

        if (is_string($guestName) === false) {
            throw new GuestLinkNotPossible();
        }

        // A player who blocks the requester is never asked - and the requester cannot tell: for them it
        // looks exactly like a question that was never answered
        if (in_array($target->id->toString(), $this->getUserBlocks->blockersOf([$requester->id->toString()]), true)) {
            return;
        }

        $this->guestLinkRequestRepository->removePending($message->requesterPlayerId, $message->guestKey);

        $request = new GuestLinkRequest(
            $message->requestId,
            $requester,
            $target,
            $message->guestKey,
            $guestName,
            $this->clock->now(),
        );
        $this->guestLinkRequestRepository->save($request);

        $this->notificationRepository->save(new Notification(
            Uuid::uuid7(),
            $target,
            NotificationType::GuestLinkRequested,
            $this->clock->now(),
            actorPlayer: $requester,
            targetGuestLinkRequest: $request,
        ));
    }
}
