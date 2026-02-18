<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\RemovePuzzleFromSellSwapList;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Repository\SellSwapListItemRepository;
use SpeedPuzzling\Web\Services\SystemMessageSender;
use SpeedPuzzling\Web\Value\SystemMessageType;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RemovePuzzleFromSellSwapListHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private PuzzleRepository $puzzleRepository,
        private SellSwapListItemRepository $sellSwapListItemRepository,
        private SystemMessageSender $systemMessageSender,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @throws PuzzleNotFound
     */
    public function __invoke(RemovePuzzleFromSellSwapList $message): void
    {
        $player = $this->playerRepository->get($message->playerId);
        $puzzle = $this->puzzleRepository->get($message->puzzleId);

        $item = $this->sellSwapListItemRepository->findByPlayerAndPuzzle($player, $puzzle);

        if ($item !== null) {
            // Send system messages BEFORE deleting (deletion nullifies FK on conversations)
            $this->systemMessageSender->sendToAllConversations(
                $item,
                SystemMessageType::ListingRemoved,
            );

            $this->sellSwapListItemRepository->delete($item);
        }
    }
}
