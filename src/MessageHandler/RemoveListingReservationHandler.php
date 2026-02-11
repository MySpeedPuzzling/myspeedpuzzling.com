<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\SellSwapListItemNotFound;
use SpeedPuzzling\Web\Message\RemoveListingReservation;
use SpeedPuzzling\Web\Repository\SellSwapListItemRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RemoveListingReservationHandler
{
    public function __construct(
        private SellSwapListItemRepository $sellSwapListItemRepository,
    ) {
    }

    /**
     * @throws SellSwapListItemNotFound
     */
    public function __invoke(RemoveListingReservation $message): void
    {
        $item = $this->sellSwapListItemRepository->get($message->sellSwapListItemId);

        if ($item->player->id->toString() !== $message->playerId) {
            throw new SellSwapListItemNotFound();
        }

        $item->removeReservation();
    }
}
