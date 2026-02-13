<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\SellSwapListItemNotFound;
use SpeedPuzzling\Web\Message\EditSellSwapListItem;
use SpeedPuzzling\Web\Repository\SellSwapListItemRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class EditSellSwapListItemHandler
{
    public function __construct(
        private SellSwapListItemRepository $sellSwapListItemRepository,
    ) {
    }

    /**
     * @throws SellSwapListItemNotFound
     */
    public function __invoke(EditSellSwapListItem $message): void
    {
        $item = $this->sellSwapListItemRepository->get($message->sellSwapListItemId);

        // Verify ownership
        if ($item->player->id->toString() !== $message->playerId) {
            throw new SellSwapListItemNotFound();
        }

        $item->changeListingType($message->listingType);
        $item->changePrice($message->price);
        $item->changeCondition($message->condition);
        $item->changeComment($message->comment);
        $item->changePublishedOnMarketplace($message->publishedOnMarketplace);
    }
}
