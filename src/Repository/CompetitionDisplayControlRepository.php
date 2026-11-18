<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionDisplayControl;

readonly final class CompetitionDisplayControlRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The event's live control - null until its first message.
     */
    public function find(string $competitionId): null|CompetitionDisplayControl
    {
        if (!Uuid::isValid($competitionId)) {
            return null;
        }

        return $this->entityManager->find(CompetitionDisplayControl::class, ['competition' => strtolower($competitionId)]);
    }

    public function save(CompetitionDisplayControl $control): void
    {
        $this->entityManager->persist($control);
    }
}
