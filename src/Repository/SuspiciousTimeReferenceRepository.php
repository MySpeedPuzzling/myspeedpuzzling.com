<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\SuspiciousTimeReference;
use SpeedPuzzling\Web\Value\PaceReference;

readonly final class SuspiciousTimeReferenceRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(SuspiciousTimeReference $reference): void
    {
        $this->entityManager->persist($reference);
    }

    /**
     * @return list<SuspiciousTimeReference>
     */
    public function all(): array
    {
        /** @var list<SuspiciousTimeReference> $references */
        $references = $this->entityManager->getRepository(SuspiciousTimeReference::class)->findAll();

        return $references;
    }

    public function findFor(PaceReference $reference): null|SuspiciousTimeReference
    {
        return $this->entityManager->getRepository(SuspiciousTimeReference::class)->findOneBy([
            'piecesRange' => $reference->piecesRange,
            'puzzlingType' => $reference->puzzlingType,
        ]);
    }
}
