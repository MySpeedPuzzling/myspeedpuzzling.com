<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\BoxTextMismatchDecision;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Value\BoxTextField;

readonly final class BoxTextMismatchDecisionRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findOne(Puzzle $puzzle, BoxTextField $field, string $valueKey): null|BoxTextMismatchDecision
    {
        $decision = $this->entityManager->getRepository(BoxTextMismatchDecision::class)->findOneBy([
            'puzzle' => $puzzle,
            'field' => $field,
            'valueKey' => $valueKey,
        ]);

        return $decision instanceof BoxTextMismatchDecision ? $decision : null;
    }

    public function save(BoxTextMismatchDecision $decision): void
    {
        $this->entityManager->persist($decision);
    }
}
