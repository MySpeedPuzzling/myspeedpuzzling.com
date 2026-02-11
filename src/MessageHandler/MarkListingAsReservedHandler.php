<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\SellSwapListItemNotFound;
use SpeedPuzzling\Web\Message\MarkListingAsReserved;
use SpeedPuzzling\Web\Repository\SellSwapListItemRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class MarkListingAsReservedHandler
{
    public function __construct(
        private SellSwapListItemRepository $sellSwapListItemRepository,
    ) {
    }

    /**
     * @throws SellSwapListItemNotFound
     */
    public function __invoke(MarkListingAsReserved $message): void
    {
        $item = $this->sellSwapListItemRepository->get($message->sellSwapListItemId);

        if ($item->player->id->toString() !== $message->playerId) {
            throw new SellSwapListItemNotFound();
        }

        $reservedForPlayerId = $message->reservedForPlayerId !== null
            ? Uuid::fromString($message->reservedForPlayerId)
            : null;

        $item->markAsReserved($reservedForPlayerId);
    }
}
