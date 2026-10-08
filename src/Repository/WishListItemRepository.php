<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\DBAL\ArrayParameterType;
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

    /**
     * The player's wishlist items of the selected puzzles, keyed by puzzle id - ids not on the wishlist are left out
     * (docs/features/collections/bulk-actions.md).
     *
     * @param list<string> $puzzleIds
     * @return array<string, WishListItem>
     */
    public function findByPlayerAndPuzzles(Player $player, array $puzzleIds): array
    {
        $puzzleIds = array_values(array_filter($puzzleIds, static fn (string $id): bool => Uuid::isValid($id)));

        if ($puzzleIds === []) {
            return [];
        }

        /** @var array<WishListItem> $items */
        $items = $this->entityManager->createQueryBuilder()
            ->select('item', 'puzzle')
            ->from(WishListItem::class, 'item')
            ->join('item.puzzle', 'puzzle')
            ->where('item.player = :player')
            ->andWhere('puzzle.id IN (:puzzleIds)')
            ->setParameter('player', $player->id->toString())
            ->setParameter('puzzleIds', $puzzleIds, ArrayParameterType::STRING)
            ->getQuery()
            ->getResult();

        $byPuzzle = [];
        foreach ($items as $item) {
            $byPuzzle[$item->puzzle->id->toString()] = $item;
        }

        return $byPuzzle;
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
