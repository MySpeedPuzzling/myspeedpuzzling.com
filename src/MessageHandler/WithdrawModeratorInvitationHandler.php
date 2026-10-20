<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\ModeratorInvitationNotFound;
use SpeedPuzzling\Web\Message\WithdrawModeratorInvitation;
use SpeedPuzzling\Web\Repository\ModeratorInvitationRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * An admin takes back an invitation not answered yet. The player is not told: the guide simply stops offering
 * the pledge (and stops being reachable, unless they are a moderator or an admin anyway).
 */
#[AsMessageHandler]
readonly final class WithdrawModeratorInvitationHandler
{
    public function __construct(
        private ModeratorInvitationRepository $moderatorInvitationRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ModeratorInvitationNotFound
     */
    public function __invoke(WithdrawModeratorInvitation $message): void
    {
        $invitation = $this->moderatorInvitationRepository->get($message->invitationId);

        if ($invitation->isPending() === false) {
            return;
        }

        $invitation->withdraw($this->clock->now());
    }
}
