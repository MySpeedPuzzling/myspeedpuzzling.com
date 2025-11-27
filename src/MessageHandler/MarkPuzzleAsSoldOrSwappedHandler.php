<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\SoldSwappedItem;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\SellSwapListItemNotFound;
use SpeedPuzzling\Web\Message\MarkPuzzleAsSoldOrSwapped;
use SpeedPuzzling\Web\Repository\CollectionItemRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\SellSwapListItemRepository;
use SpeedPuzzling\Web\Repository\SoldSwappedItemRepository;
use SpeedPuzzling\Web\Repository\WishListItemRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class MarkPuzzleAsSoldOrSwappedHandler
{
    public function __construct(
        private SellSwapListItemRepository $sellSwapListItemRepository,
        private SoldSwappedItemRepository $soldSwappedItemRepository,
        private CollectionItemRepository $collectionItemRepository,
        private WishListItemRepository $wishListItemRepository,
        private PlayerRepository $playerRepository,
    ) {
    }

    /**
     * @throws SellSwapListItemNotFound
     */
    public function __invoke(MarkPuzzleAsSoldOrSwapped $message): void
    {
        $item = $this->sellSwapListItemRepository->get($message->sellSwapListItemId);

        // Verify ownership
        if ($item->player->id->toString() !== $message->playerId) {
            throw new SellSwapListItemNotFound();
        }

        // Parse buyer input using #code pattern
        $buyerPlayer = null;
        $buyerName = null;

        if ($message->buyerInput !== null && $message->buyerInput !== '') {
            $buyerData = $this->parseBuyerInput($message->buyerInput);
            $buyerPlayer = $buyerData['player'];
            $buyerName = $buyerData['name'];
        }

        // Create history record
        $soldSwappedItem = new SoldSwappedItem(
            Uuid::uuid7(),
            $item->player,
            $item->puzzle,
            $buyerPlayer,
            $buyerName,
            $item->listingType,
            $item->price,
            new DateTimeImmutable(),
        );

        $this->soldSwappedItemRepository->save($soldSwappedItem);

        // Delete from sell/swap list
        $this->sellSwapListItemRepository->delete($item);

        // Delete from all collections
        $collectionItems = $this->collectionItemRepository->findByPlayerAndPuzzle(
            $item->player->id->toString(),
            $item->puzzle->id->toString(),
        );

        foreach ($collectionItems as $collectionItem) {
            $this->collectionItemRepository->delete($collectionItem);
        }

        // Delete from wishlist if present
        $wishListItem = $this->wishListItemRepository->findByPlayerAndPuzzle($item->player, $item->puzzle);

        if ($wishListItem !== null) {
            $this->wishListItemRepository->delete($wishListItem);
        }
    }

    /**
     * Parse buyer input - if starts with # it's a player code lookup, otherwise it's free text name.
     *
     * @return array{player: null|Player, name: null|string}
     */
    private function parseBuyerInput(string $buyerInput): array
    {
        $isRegisteredPlayer = str_starts_with($buyerInput, '#');
        $buyerInput = trim($buyerInput, "# \t\n\r\0");

        if ($buyerInput === '') {
            return ['player' => null, 'name' => null];
        }

        try {
            if ($isRegisteredPlayer) {
                $player = $this->playerRepository->getByCode($buyerInput);
                return ['player' => $player, 'name' => null];
            }
        } catch (PlayerNotFound) {
            // Player not found by code, treat as free text
        }

        return ['player' => null, 'name' => $buyerInput];
    }
}
