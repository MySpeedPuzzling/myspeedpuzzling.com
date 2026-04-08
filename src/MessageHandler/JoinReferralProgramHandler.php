<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\JoinReferralProgram;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class JoinReferralProgramHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(JoinReferralProgram $message): void
    {
        $player = $this->playerRepository->get($message->playerId);

        if ($player->referralProgramJoinedAt !== null) {
            return;
        }

        $player->joinReferralProgram($this->clock->now());
    }
}
