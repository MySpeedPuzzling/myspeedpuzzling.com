<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\MarkFavoritesSeen;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Moves "your last visit" of the Hub's Favorites feed to now - unless it moved less than ten minutes ago, which the
 * entity decides (Player::markFavoritesSeen(); nothing changes, so no UPDATE).
 */
#[AsMessageHandler]
readonly final class MarkFavoritesSeenHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(MarkFavoritesSeen $message): void
    {
        $player = $this->playerRepository->get($message->playerId);

        $player->markFavoritesSeen($this->clock->now());
    }
}
