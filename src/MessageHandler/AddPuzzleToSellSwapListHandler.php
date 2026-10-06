<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Services\SecretPuzzleAccess;
use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\SellSwapListItem;
use SpeedPuzzling\Web\Exceptions\MarketplaceBanned;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\AddPuzzleToSellSwapList;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Repository\SellSwapListItemRepository;
use SpeedPuzzling\Web\Services\ListingEventLinks;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AddPuzzleToSellSwapListHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private PuzzleRepository $puzzleRepository,
        private SellSwapListItemRepository $sellSwapListItemRepository,
        private ListingEventLinks $listingEventLinks,
        private SecretPuzzleAccess $secretPuzzleAccess,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @throws PuzzleNotFound
     * @throws MarketplaceBanned
     */
    public function __invoke(AddPuzzleToSellSwapList $message): void
    {
        $player = $this->playerRepository->get($message->playerId);

        if ($player->marketplaceBanned) {
            throw new MarketplaceBanned();
        }

        $puzzle = $this->puzzleRepository->get($message->puzzleId);

        // A puzzle a competition keeps secret is nobody's to use but its organisers' (SecretPuzzleAccess)

        $this->secretPuzzleAccess->assertPuzzleUsableBy($puzzle, $message->playerId);

        $existingItem = $this->sellSwapListItemRepository->findByPlayerAndPuzzle($player, $puzzle);

        if ($existingItem !== null) {
            $existingItem->changeListingType($message->listingType);
            $existingItem->changePrice($message->price);
            $existingItem->changeCondition($message->condition);
            $existingItem->changeComment($message->comment);
            $existingItem->changePublishedOnMarketplace($message->publishedOnMarketplace);

            if ($message->eventIds !== null) {
                $this->listingEventLinks->syncWithListingForm($existingItem, $message->eventIds);
            }

            return;
        }

        $sellSwapListItem = new SellSwapListItem(
            Uuid::uuid7(),
            $player,
            $puzzle,
            $message->listingType,
            $message->price,
            $message->condition,
            $message->comment,
            new DateTimeImmutable(),
            $message->publishedOnMarketplace,
        );

        $this->sellSwapListItemRepository->save($sellSwapListItem);

        if ($message->eventIds !== null) {
            $this->listingEventLinks->syncWithListingForm($sellSwapListItem, $message->eventIds);
        }
    }
}
