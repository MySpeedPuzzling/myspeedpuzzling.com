<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\EditSystemCollection;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class EditSystemCollectionHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(EditSystemCollection $message): void
    {
        $player = $this->playerRepository->get($message->playerId);

        $player->changePuzzleCollectionVisibility($message->visibility);
    }
}
