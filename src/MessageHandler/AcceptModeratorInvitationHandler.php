<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\ModeratorInvitationNotFound;
use SpeedPuzzling\Web\Message\AcceptModeratorInvitation;
use SpeedPuzzling\Web\Message\GrantModeratorRole;
use SpeedPuzzling\Web\Repository\ModeratorInvitationRepository;
use SpeedPuzzling\Web\Services\Moderators\ModeratorGuide;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The player read the guide and agreed to its pledge: the agreement is recorded on the invitation, then the role is
 * granted by GrantModeratorRole (welcome notification + e-mail) in the same transaction.
 */
#[AsMessageHandler]
readonly final class AcceptModeratorInvitationHandler
{
    public function __construct(
        private ModeratorInvitationRepository $moderatorInvitationRepository,
        private ClockInterface $clock,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @throws ModeratorInvitationNotFound
     */
    public function __invoke(AcceptModeratorInvitation $message): void
    {
        $invitation = $this->moderatorInvitationRepository->findPendingForPlayer($message->playerId)
            ?? throw new ModeratorInvitationNotFound();

        $invitation->accept(ModeratorGuide::VERSION, $this->clock->now());

        $this->messageBus->dispatch(new GrantModeratorRole($message->playerId));
    }
}
