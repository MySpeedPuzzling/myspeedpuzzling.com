<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionAdvancementStep;
use SpeedPuzzling\Web\Exceptions\AdvancementStepNotFound;

readonly final class CompetitionAdvancementStepRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws AdvancementStepNotFound
     */
    public function get(string $stepId): CompetitionAdvancementStep
    {
        if (!Uuid::isValid($stepId)) {
            throw new AdvancementStepNotFound();
        }

        $step = $this->entityManager->find(CompetitionAdvancementStep::class, $stepId);

        return $step ?? throw new AdvancementStepNotFound();
    }

    /**
     * @return list<CompetitionAdvancementStep> by position, then creation
     */
    public function ofCompetition(Competition $competition): array
    {
        /** @var list<CompetitionAdvancementStep> $steps */
        $steps = $this->entityManager->createQueryBuilder()
            ->select('step')
            ->from(CompetitionAdvancementStep::class, 'step')
            ->where('step.competition = :competition')
            ->setParameter('competition', $competition)
            ->orderBy('step.position')
            ->addOrderBy('step.createdAt')
            ->addOrderBy('step.id')
            ->getQuery()
            ->getResult();

        return $steps;
    }

    public function save(CompetitionAdvancementStep $step): void
    {
        $this->entityManager->persist($step);
    }

    public function delete(CompetitionAdvancementStep $step): void
    {
        $this->entityManager->remove($step);
    }
}
