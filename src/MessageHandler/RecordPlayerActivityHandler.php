<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\RecordPlayerActivity;
use SpeedPuzzling\Web\Repository\PlayerActivityDayRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RecordPlayerActivityHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private PlayerActivityDayRepository $playerActivityDayRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RecordPlayerActivity $message): void
    {
        // Lookup only, never create - a session whose player is gone records nothing
        $player = $this->playerRepository->findByUserId($message->userId);

        if ($player === null) {
            return;
        }

        $this->playerActivityDayRepository->recordActivity($player->id, $this->clock->now());
    }
}
