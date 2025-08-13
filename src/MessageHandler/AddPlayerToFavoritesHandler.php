<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\AddPlayerToFavorites;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AddPlayerToFavoritesHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
    ) {
    }

    /**
     * @throws CanNotFavoriteYourself
     * @throws CouldNotGenerateUniqueCode
     * @throws PlayerIsAlreadyInFavorites
     * @throws PlayerNotFound
     */
    public function __invoke(AddPlayerToFavorites $message): void
    {
        $player = $this->playerRepository->getByUserIdCreateIfNotExists($message->currentUserId);
        $favoritePlayer = $this->playerRepository->get($message->favoritePlayerId);

        $player->addFavoritePlayer($favoritePlayer);
    }
}
