<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CustomField;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\CustomFieldNotFound;

readonly final class CustomFieldRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(CustomField $customField): void
    {
        $this->entityManager->persist($customField);
    }

    /**
     * The player's own field - another player's is "not found" like one that does not exist.
     *
     * @throws CustomFieldNotFound
     */
    public function getOwnedBy(string $customFieldId, Player $player): CustomField
    {
        if (Uuid::isValid($customFieldId) === false) {
            throw new CustomFieldNotFound();
        }

        $customField = $this->entityManager->find(CustomField::class, $customFieldId);

        if ($customField === null || $customField->player->id->equals($player->id) === false) {
            throw new CustomFieldNotFound();
        }

        return $customField;
    }

    /**
     * Every field of the player, the archived ones too, in their order.
     *
     * @return list<CustomField>
     */
    public function ofPlayer(Player $player): array
    {
        return $this->entityManager->getRepository(CustomField::class)->findBy(
            ['player' => $player],
            ['position' => 'ASC', 'createdAt' => 'ASC'],
        );
    }
}
