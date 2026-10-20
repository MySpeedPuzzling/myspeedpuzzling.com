<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\ModeratorInvitationNotFound;
use SpeedPuzzling\Web\Message\DeclineModeratorInvitation;
use SpeedPuzzling\Web\Repository\ModeratorInvitationRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "Not now" - nobody is told, the admins see it on /admin/moderators and may ask again later
 */
#[AsMessageHandler]
readonly final class DeclineModeratorInvitationHandler
{
    public function __construct(
        private ModeratorInvitationRepository $moderatorInvitationRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ModeratorInvitationNotFound
     */
    public function __invoke(DeclineModeratorInvitation $message): void
    {
        $invitation = $this->moderatorInvitationRepository->findPendingForPlayer($message->playerId)
            ?? throw new ModeratorInvitationNotFound();

        $invitation->decline($this->clock->now());
    }
}
