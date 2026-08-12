<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\SuspendFromReferralProgram;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class SuspendFromReferralProgramHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
    ) {
    }

    public function __invoke(SuspendFromReferralProgram $message): void
    {
        $player = $this->playerRepository->get($message->playerId);
        $player->suspendFromReferralProgram();
    }
}
