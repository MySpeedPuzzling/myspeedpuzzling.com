<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\EditFeaturesOptions;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class EditFeaturesOptionsHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(EditFeaturesOptions $message): void
    {
        $player = $this->playerRepository->get($message->playerId);

        $player->changeStreakOptedOut($message->streakOptedOut);
        $player->changeRankingOptedOut($message->rankingOptedOut);
        $player->changeTimePredictionsOptedOut($message->timePredictionsOptedOut);
    }
}
