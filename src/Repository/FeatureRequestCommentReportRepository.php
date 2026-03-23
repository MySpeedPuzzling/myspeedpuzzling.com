<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\FeatureRequestCommentReport;

readonly final class FeatureRequestCommentReportRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(FeatureRequestCommentReport $report): void
    {
        $this->entityManager->persist($report);
    }
}
