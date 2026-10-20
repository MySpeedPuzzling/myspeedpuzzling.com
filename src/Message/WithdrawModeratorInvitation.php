<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class WithdrawModeratorInvitation
{
    public function __construct(
        public string $invitationId,
    ) {
    }
}
