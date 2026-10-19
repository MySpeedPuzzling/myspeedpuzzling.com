<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\SolvingTimePredictionRecord;
use SpeedPuzzling\Web\Value\PredictorKind;

readonly final class SolvingTimePredictionRecordRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(SolvingTimePredictionRecord $record): void
    {
        $this->entityManager->persist($record);
    }

    /**
     * @param list<string> $timeIds
     * @return array<string, SolvingTimePredictionRecord> keyed by time id
     */
    public function forTimes(array $timeIds, PredictorKind $predictor, int $modelVersion): array
    {
        if ($timeIds === []) {
            return [];
        }

        /** @var list<SolvingTimePredictionRecord> $records */
        $records = $this->entityManager->createQueryBuilder()
            ->select('record')
            ->from(SolvingTimePredictionRecord::class, 'record')
            ->where('record.time IN (:timeIds)')
            ->andWhere('record.predictor = :predictor')
            ->andWhere('record.modelVersion = :modelVersion')
            ->setParameter('timeIds', $timeIds)
            ->setParameter('predictor', $predictor)
            ->setParameter('modelVersion', $modelVersion)
            ->getQuery()
            ->getResult();

        $byTime = [];

        foreach ($records as $record) {
            $byTime[$record->time->id->toString()] = $record;
        }

        return $byTime;
    }
}
