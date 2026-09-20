<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\RenameTeamGuest;
use SpeedPuzzling\Web\Services\PuzzlingTeamMemberConversion;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RenameTeamGuestHandler
{
    public function __construct(
        private PuzzlingTeamMemberConversion $conversion,
    ) {
    }

    /**
     * @return int how many pairs/teams were changed
     */
    public function __invoke(RenameTeamGuest $message): int
    {
        // Only teams the player is a member of are touched - the same right as editing a group time
        return $this->conversion->renameGuest($message->playerId, $message->guestKey, mb_substr($message->newName, 0, 100));
    }
}
