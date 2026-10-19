<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\ImageEstimateEvaluation;

readonly final class ImageEstimateEvaluationRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(ImageEstimateEvaluation $evaluation): void
    {
        $this->entityManager->persist($evaluation);
    }
}
