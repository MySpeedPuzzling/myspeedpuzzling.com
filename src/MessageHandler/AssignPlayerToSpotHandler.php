<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\AssignPlayerToSpot;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\TableSpotRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AssignPlayerToSpotHandler
{
    public function __construct(
        private TableSpotRepository $tableSpotRepository,
        private PlayerRepository $playerRepository,
    ) {
    }

    public function __invoke(AssignPlayerToSpot $message): void
    {
        $spot = $this->tableSpotRepository->get($message->spotId);

        if ($message->playerId !== null) {
            $player = $this->playerRepository->get($message->playerId);
            $spot->assignPlayer($player);
        } elseif ($message->playerName !== null) {
            $spot->assignManualName($message->playerName);
        } else {
            $spot->clearAssignment();
        }
    }
}
