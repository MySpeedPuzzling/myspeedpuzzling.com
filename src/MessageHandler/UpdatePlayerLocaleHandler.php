<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\UpdatePlayerLocale;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class UpdatePlayerLocaleHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
    ) {
    }

    public function __invoke(UpdatePlayerLocale $message): void
    {
        $player = $this->playerRepository->get($message->playerId);
        $player->changeLocale($message->locale);
    }
}
