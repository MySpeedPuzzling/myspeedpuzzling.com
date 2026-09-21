<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Exceptions\GuestLinkRequestNotFound;
use SpeedPuzzling\Web\Message\AnswerGuestLink;
use SpeedPuzzling\Web\Repository\GuestLinkRequestRepository;
use SpeedPuzzling\Web\Repository\NotificationRepository;
use SpeedPuzzling\Web\Services\PuzzlingTeamMemberConversion;
use SpeedPuzzling\Web\Value\NotificationType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AnswerGuestLinkHandler
{
    public function __construct(
        private GuestLinkRequestRepository $guestLinkRequestRepository,
        private NotificationRepository $notificationRepository,
        private PuzzlingTeamMemberConversion $conversion,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws GuestLinkRequestNotFound also when it is somebody else's question, or already answered
     */
    public function __invoke(AnswerGuestLink $message): void
    {
        $request = $this->guestLinkRequestRepository->get($message->requestId);

        // Only whoever was asked may answer, and only once
        if ($request->target->id->toString() !== $message->playerId || $request->isPending() === false) {
            throw new GuestLinkRequestNotFound();
        }

        $request->resolve($message->accept, $this->clock->now());

        if ($message->accept === false) {
            return;
        }

        $this->conversion->guestToPlayer(
            $request->requester->id->toString(),
            $request->guestKey,
            $request->target->id->toString(),
        );

        $this->notificationRepository->save(new Notification(
            Uuid::uuid7(),
            $request->requester,
            NotificationType::GuestLinkAccepted,
            $this->clock->now(),
            actorPlayer: $request->target,
            targetGuestLinkRequest: $request,
        ));
    }
}
