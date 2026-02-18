<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ConversationReport;
use SpeedPuzzling\Web\Exceptions\ConversationReportNotFound;

readonly final class ConversationReportRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws ConversationReportNotFound
     */
    public function get(string $reportId): ConversationReport
    {
        if (!Uuid::isValid($reportId)) {
            throw new ConversationReportNotFound();
        }

        $report = $this->entityManager->find(ConversationReport::class, $reportId);

        if ($report === null) {
            throw new ConversationReportNotFound();
        }

        return $report;
    }

    public function save(ConversationReport $report): void
    {
        $this->entityManager->persist($report);
    }
}
