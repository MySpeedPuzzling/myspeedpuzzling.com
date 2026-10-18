<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleTagCorrection;

readonly final class PuzzleTagCorrectionRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findOne(Puzzle $puzzle, string $tagKey): null|PuzzleTagCorrection
    {
        $correction = $this->entityManager->getRepository(PuzzleTagCorrection::class)->findOneBy([
            'puzzle' => $puzzle,
            'tagKey' => $tagKey,
        ]);

        return $correction instanceof PuzzleTagCorrection ? $correction : null;
    }

    public function save(PuzzleTagCorrection $correction): void
    {
        $this->entityManager->persist($correction);
    }

    public function delete(PuzzleTagCorrection $correction): void
    {
        $this->entityManager->remove($correction);
    }
}
