<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\RemovePuzzlesFromSellSwapList;
use SpeedPuzzling\Web\Repository\ConversationRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\SellSwapListItemRepository;
use SpeedPuzzling\Web\Services\SystemMessageSender;
use SpeedPuzzling\Web\Value\SystemMessageType;
use SpeedPuzzling\Web\Value\SelectedPuzzlesOutcome;
use SpeedPuzzling\Web\Services\ListingRequest\ListingRequestCloser;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Takes every selected listing off the sell/swap list; its conversations are told, like a single removal.
 */
#[AsMessageHandler]
readonly final class RemovePuzzlesFromSellSwapListHandler
{
    public function __construct(
        private ListingRequestCloser $listingRequestCloser,
        private ConversationRepository $conversationRepository,
        private PlayerRepository $playerRepository,
        private SellSwapListItemRepository $sellSwapListItemRepository,
        private SystemMessageSender $systemMessageSender,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(RemovePuzzlesFromSellSwapList $message): SelectedPuzzlesOutcome
    {
        $player = $this->playerRepository->get($message->playerId);
        $puzzleIds = array_values(array_unique($message->puzzleIds));
        $items = $this->sellSwapListItemRepository->findByPlayerAndPuzzles($player, $puzzleIds);

        $this->listingRequestCloser->listingsGoing($items);

        // Every conversation is told before any listing is deleted: the sender flushes, and a flush must not meet a
        // conversation whose listing an earlier step already deleted
        foreach ($items as $item) {
            $this->systemMessageSender->sendToAllConversations($item, SystemMessageType::ListingRemoved);
        }

        foreach ($items as $item) {
            // The chats about it stay; their link goes before a later flush of this request meets the deleted listing
            foreach ($this->conversationRepository->findAllByListItem($item) as $conversation) {
                $conversation->listingLeft();
            }

            $this->sellSwapListItemRepository->delete($item);
        }

        return new SelectedPuzzlesOutcome(
            changed: count($items),
            skipped: count($puzzleIds) - count($items),
        );
    }
}
