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

    public function delete(SolvingTimePredictionRecord $record): void
    {
        $this->entityManager->remove($record);
    }

    /**
     * Several predictors' rows of the times at once.
     *
     * @param list<string> $timeIds
     * @param list<PredictorKind> $predictors
     * @return array<string, array<string, SolvingTimePredictionRecord>> predictor => time id => row
     */
    public function forTimesByPredictor(array $timeIds, array $predictors, int $modelVersion): array
    {
        if ($timeIds === [] || $predictors === []) {
            return [];
        }

        /** @var list<SolvingTimePredictionRecord> $records */
        $records = $this->entityManager->createQueryBuilder()
            ->select('record')
            ->from(SolvingTimePredictionRecord::class, 'record')
            ->where('record.time IN (:timeIds)')
            ->andWhere('record.predictor IN (:predictors)')
            ->andWhere('record.modelVersion = :modelVersion')
            ->setParameter('timeIds', $timeIds)
            ->setParameter('predictors', array_map(static fn (PredictorKind $predictor): string => $predictor->value, $predictors))
            ->setParameter('modelVersion', $modelVersion)
            ->getQuery()
            ->getResult();

        $byPredictor = [];

        foreach ($records as $record) {
            $byPredictor[$record->predictor->value][$record->time->id->toString()] = $record;
        }

        return $byPredictor;
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
