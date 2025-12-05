<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\WishListItem;
use SpeedPuzzling\Web\Exceptions\WishListItemNotFound;

readonly final class WishListItemRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws WishListItemNotFound
     */
    public function get(string $wishListItemId): WishListItem
    {
        if (!Uuid::isValid($wishListItemId)) {
            throw new WishListItemNotFound();
        }

        $wishListItem = $this->entityManager->find(WishListItem::class, $wishListItemId);

        if ($wishListItem === null) {
            throw new WishListItemNotFound();
        }

        return $wishListItem;
    }

    public function findByPlayerAndPuzzle(Player $player, Puzzle $puzzle): null|WishListItem
    {
        return $this->entityManager->getRepository(WishListItem::class)
            ->findOneBy([
                'player' => $player,
                'puzzle' => $puzzle,
            ]);
    }

    public function findByPlayerIdAndPuzzleIdWithRemoveFlag(string $playerId, string $puzzleId): null|WishListItem
    {
        return $this->entityManager->getRepository(WishListItem::class)
            ->findOneBy([
                'player' => $playerId,
                'puzzle' => $puzzleId,
                'removeOnCollectionAdd' => true,
            ]);
    }

    public function countByPlayer(string $playerId): int
    {
        return $this->entityManager->getRepository(WishListItem::class)
            ->count([
                'player' => $playerId,
            ]);
    }

    public function save(WishListItem $wishListItem): void
    {
        $this->entityManager->persist($wishListItem);
    }

    public function delete(WishListItem $wishListItem): void
    {
        $this->entityManager->remove($wishListItem);
    }
}
