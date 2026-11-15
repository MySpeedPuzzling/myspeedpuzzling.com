<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ImportLayout;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\ImportLayoutNotFound;

readonly final class ImportLayoutRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(ImportLayout $layout): void
    {
        $this->entityManager->persist($layout);
    }

    public function delete(ImportLayout $layout): void
    {
        $this->entityManager->remove($layout);
    }

    public function find(string $layoutId): null|ImportLayout
    {
        if (Uuid::isValid($layoutId) === false) {
            return null;
        }

        return $this->entityManager->find(ImportLayout::class, $layoutId);
    }

    /**
     * The player's own layout - another player's is "not found" like one that does not exist.
     *
     * @throws ImportLayoutNotFound
     */
    public function getOwnedBy(string $layoutId, string $playerId): ImportLayout
    {
        $layout = $this->find($layoutId);

        if ($layout === null || $layout->isOwnedBy($playerId) === false) {
            throw new ImportLayoutNotFound();
        }

        return $layout;
    }

    public function findBySignature(Player $player, string $signature): null|ImportLayout
    {
        return $this->entityManager->getRepository(ImportLayout::class)->findOneBy(['player' => $player, 'signature' => $signature]);
    }

    /**
     * The player's layouts, the one used longest ago first.
     *
     * @return list<ImportLayout>
     */
    public function ofPlayerOldestFirst(Player $player): array
    {
        return $this->entityManager->getRepository(ImportLayout::class)->findBy(['player' => $player], ['lastUsedAt' => 'ASC', 'id' => 'ASC']);
    }
}
