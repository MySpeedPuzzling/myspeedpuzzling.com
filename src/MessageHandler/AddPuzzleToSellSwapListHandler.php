<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

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
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class AddPuzzleToSellSwapListHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private PuzzleRepository $puzzleRepository,
        private SellSwapListItemRepository $sellSwapListItemRepository,
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

        $existingItem = $this->sellSwapListItemRepository->findByPlayerAndPuzzle($player, $puzzle);

        if ($existingItem !== null) {
            $existingItem->changeListingType($message->listingType);
            $existingItem->changePrice($message->price);
            $existingItem->changeCondition($message->condition);
            $existingItem->changeComment($message->comment);
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
        );

        $this->sellSwapListItemRepository->save($sellSwapListItem);
    }
}
