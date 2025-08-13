<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\RemovePlayerFromFavorites;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RemovePlayerFromFavoritesHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
    ) {
    }

    /**
     * @throws CouldNotGenerateUniqueCode
     * @throws PlayerIsNotInFavorites
     * @throws PlayerNotFound
     */
    public function __invoke(RemovePlayerFromFavorites $message): void
    {
        $player = $this->playerRepository->getByUserIdCreateIfNotExists($message->currentUserId);
        $favoritePlayer = $this->playerRepository->get($message->favoritePlayerId);

        $player->removeFavoritePlayer($favoritePlayer);
    }
}
