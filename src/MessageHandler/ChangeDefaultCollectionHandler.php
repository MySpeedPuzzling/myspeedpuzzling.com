<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CollectionNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\ChangeDefaultCollection;
use SpeedPuzzling\Web\Repository\CollectionRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ChangeDefaultCollectionHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private CollectionRepository $collectionRepository,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @throws CollectionNotFound somebody else's collection or an unknown one
     */
    public function __invoke(ChangeDefaultCollection $message): void
    {
        $player = $this->playerRepository->get($message->playerId);

        $player->changeDefaultCollection($this->collectionRepository->getOwnedBy($message->collectionId, $player));
    }
}
