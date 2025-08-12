<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use SpeedPuzzling\Web\Entity\CollectionFolder;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\CollectionFolderNotFound;

/**
 * @extends EntityRepository<CollectionFolder>
 */
readonly final class CollectionFolderRepository
{
    private EntityRepository $repository;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
        $this->repository = $entityManager->getRepository(CollectionFolder::class);
    }

    public function save(CollectionFolder $folder): void
    {
        $this->entityManager->persist($folder);
        $this->entityManager->flush();
    }

    public function get(string $id): CollectionFolder
    {
        $folder = $this->repository->find($id);

        if ($folder === null) {
            throw new CollectionFolderNotFound();
        }

        return $folder;
    }

    public function findSystemFolder(Player $player, string $systemKey): null|CollectionFolder
    {
        return $this->repository->findOneBy([
            'player' => $player,
            'systemKey' => $systemKey,
            'isSystem' => true,
        ]);
    }

    /**
     * @return array<CollectionFolder>
     */
    public function findAllForPlayer(Player $player): array
    {
        return $this->repository->findBy(
            ['player' => $player],
            ['isSystem' => 'ASC', 'name' => 'ASC']
        );
    }

    /**
     * @return array<CollectionFolder>
     */
    public function findUserFoldersForPlayer(Player $player): array
    {
        return $this->repository->findBy(
            ['player' => $player, 'isSystem' => false],
            ['name' => 'ASC']
        );
    }

    public function delete(CollectionFolder $folder): void
    {
        if ($folder->isSystem) {
            throw new \DomainException('Cannot delete system folder');
        }

        $this->entityManager->remove($folder);
        $this->entityManager->flush();
    }

    private function getSystemFolderName(string $systemKey): string
    {
        return match ($systemKey) {
            'solved' => 'Solved Puzzles',
            'lended_out' => 'Lended Out', 
            'lended_from' => 'Borrowed',
            default => throw new \InvalidArgumentException("Unknown system folder key: {$systemKey}"),
        };
    }
}