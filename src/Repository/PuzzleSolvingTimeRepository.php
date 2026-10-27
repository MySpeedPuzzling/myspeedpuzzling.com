<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\PuzzlingTeamMember;
use SpeedPuzzling\Web\Entity\SolvingTimePredictionRecord;
use SpeedPuzzling\Web\Exceptions\PuzzleSolvingTimeNotFound;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\CandidatePredictionCalculator;
use SpeedPuzzling\Web\Value\PredictorKind;
use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\TimePredictionSource;

readonly final class PuzzleSolvingTimeRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws PuzzleSolvingTimeNotFound
     */
    public function get(string $puzzleSolvingTimeId): PuzzleSolvingTime
    {
        if (!Uuid::isValid($puzzleSolvingTimeId)) {
            throw new PuzzleSolvingTimeNotFound();
        }

        $puzzle = $this->entityManager->find(PuzzleSolvingTime::class, $puzzleSolvingTimeId);

        return $puzzle ?? throw new PuzzleSolvingTimeNotFound();
    }

    public function findById(UuidInterface $puzzleSolvingTimeId): null|PuzzleSolvingTime
    {
        return $this->entityManager->find(PuzzleSolvingTime::class, $puzzleSolvingTimeId);
    }

    /**
     * @param list<string> $puzzleSolvingTimeIds
     * @return array<string, PuzzleSolvingTime> keyed by id - the ones that exist
     */
    public function findByIds(array $puzzleSolvingTimeIds): array
    {
        if ($puzzleSolvingTimeIds === []) {
            return [];
        }

        /** @var list<PuzzleSolvingTime> $times */
        $times = $this->entityManager->createQueryBuilder()
            ->select('time')
            ->from(PuzzleSolvingTime::class, 'time')
            ->where('time.id IN (:ids)')
            ->setParameter('ids', $puzzleSolvingTimeIds)
            ->getQuery()
            ->getResult();

        $byId = [];

        foreach ($times as $time) {
            $byId[$time->id->toString()] = $time;
        }

        return $byId;
    }

    /**
     * Results of the pairs/teams with a guest written as several people ("Anna, Ben, Clara") - see SplitCombinedGuests
     *
     * @return list<PuzzleSolvingTime>
     */
    public function findWithCombinedGuest(): array
    {
        /** @var list<PuzzleSolvingTime> $times */
        $times = $this->entityManager->createQueryBuilder()
            ->select('time')
            ->from(PuzzleSolvingTime::class, 'time')
            ->where('time.puzzlingTeam IN (SELECT IDENTITY(guest.team) FROM ' . PuzzlingTeamMember::class . " guest WHERE guest.player IS NULL AND guest.guestName LIKE '%,%')")
            ->orderBy('time.id')
            ->getQuery()
            ->getResult();

        return $times;
    }

    public function save(PuzzleSolvingTime $solvingTime): void
    {
        $this->entityManager->persist($solvingTime);
    }

    /**
     * The same path as deleting a result by hand: PuzzleSolvingTimeDeleted recalculates statistics and insights.
     */
    public function delete(PuzzleSolvingTime $solvingTime): void
    {
        $this->entityManager->remove($solvingTime);
    }

    /**
     * Solo times with seconds of the player whose prediction was not evaluated yet or (with $withSideRows - once the
     * model is fitted) that have no side-by-side row of the current model version (solving_time_prediction), plus the
     * listed ones - and with $recomputeReconstructed (the time of the current fit) every one with a reconstructed
     * prediction computed no later than it (shown, or the current model's side-by-side row), and $imageTimeIds (first
     * tries waiting for their image / blend rows - GetTimesAwaitingImagePredictions, native SQL the fingerprint needs).
     * Locked for the rest of the transaction: an edit of one of them waits until the new predictions are committed,
     * and two reconstructions of one player never interleave.
     *
     * @param list<string> $alsoTimeIds
     * @param list<string> $imageTimeIds
     * @return list<PuzzleSolvingTime>
     */
    public function findForPredictionOfPlayer(
        string $playerId,
        array $alsoTimeIds = [],
        null|DateTimeImmutable $recomputeReconstructed = null,
        bool $withSideRows = true,
        array $imageTimeIds = [],
        // The candidate model (v4): its row missing (once it is fitted), and with its fit time the reconstructed rows from before it
        bool $withCandidateRows = false,
        null|DateTimeImmutable $recomputeCandidateBefore = null,
    ): array {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('time')
            ->from(PuzzleSolvingTime::class, 'time')
            ->where('time.player = :playerId')
            ->andWhere('time.puzzlingType = :solo')
            ->andWhere('time.secondsToSolve IS NOT NULL')
            ->setParameter('playerId', $playerId)
            ->setParameter('solo', PuzzlingType::Solo);

        // Pending, or without the current model's side-by-side row (solving_time_prediction) once the model is fitted
        $conditions = ['time.predictable IS NULL'];

        if ($withSideRows) {
            $conditions[] = 'NOT EXISTS (SELECT 1 FROM ' . SolvingTimePredictionRecord::class . ' record WHERE record.time = time AND record.predictor = :statistical AND record.modelVersion = :modelVersion)';
        }

        if ($alsoTimeIds !== []) {
            $conditions[] = 'time.id IN (:alsoTimeIds)';
            $queryBuilder->setParameter('alsoTimeIds', $alsoTimeIds);
        }

        if ($imageTimeIds !== []) {
            $conditions[] = 'time.id IN (:imageTimeIds)';
            $queryBuilder->setParameter('imageTimeIds', $imageTimeIds);
        }

        if ($withCandidateRows) {
            $conditions[] = 'NOT EXISTS (SELECT 1 FROM ' . SolvingTimePredictionRecord::class . ' candidateRecord WHERE candidateRecord.time = time AND candidateRecord.predictor = :candidate AND candidateRecord.modelVersion = :candidateModelVersion)';
            $queryBuilder
                ->setParameter('candidate', PredictorKind::Candidate)
                ->setParameter('candidateModelVersion', CandidatePredictionCalculator::MODEL_VERSION);

            if ($recomputeCandidateBefore !== null) {
                $conditions[] = 'EXISTS (SELECT 1 FROM ' . SolvingTimePredictionRecord::class . ' staleCandidate WHERE staleCandidate.time = time AND staleCandidate.predictor = :candidate AND staleCandidate.modelVersion = :candidateModelVersion AND staleCandidate.source = :candidateReconstructed AND staleCandidate.computedAt <= :candidateFittedAt)';
                $queryBuilder
                    ->setParameter('candidateReconstructed', TimePredictionSource::Reconstructed)
                    ->setParameter('candidateFittedAt', $recomputeCandidateBefore);
            }
        }

        // Every reconstruction again - shown (of any model version) and the current model's side-by-side row; live
        // ones are history
        if ($recomputeReconstructed !== null) {
            $conditions[] = '(time.predictionSource = :reconstructed AND time.predictionComputedAt <= :fittedAt)';
            $conditions[] = 'EXISTS (SELECT 1 FROM ' . SolvingTimePredictionRecord::class . ' reconstructedRecord WHERE reconstructedRecord.time = time AND reconstructedRecord.predictor = :statistical AND reconstructedRecord.modelVersion = :modelVersion AND reconstructedRecord.source = :reconstructed AND reconstructedRecord.computedAt <= :fittedAt)';
            $queryBuilder
                ->setParameter('reconstructed', TimePredictionSource::Reconstructed)
                ->setParameter('fittedAt', $recomputeReconstructed);
        }

        if ($withSideRows || $recomputeReconstructed !== null) {
            $queryBuilder
                ->setParameter('statistical', PredictorKind::Statistical)
                ->setParameter('modelVersion', TimePredictionCalculator::MODEL_VERSION);
        }

        $queryBuilder->andWhere(implode(' OR ', $conditions));

        /** @var list<PuzzleSolvingTime> */
        return $queryBuilder
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();
    }

    /**
     * The times that belong to the round, in its current competition: linked to it (competition_round_id), or - not
     * linked yet - solved in its category on one of its puzzles, the rule of SolvingTimeRoundResolver /
     * RoundResultsReconciler (a puzzle is in at most one round per category per competition, so such a time can only
     * belong to this round). MoveRoundToCompetition moves them all with the round.
     *
     * Explicit links only: a series pick's edition follows the matching rule, not the round - the reconcile both
     * competitions get after the move re-matches it (docs/features/events-page/high-frequency-series.md P29).
     *
     * @return list<PuzzleSolvingTime>
     */
    public function findByCompetitionRound(CompetitionRound $round): array
    {
        /** @var list<PuzzleSolvingTime> */
        return $this->entityManager->createQueryBuilder()
            ->select('time')
            ->from(PuzzleSolvingTime::class, 'time')
            ->where('time.competition = :competition')
            ->andWhere('time.competitionSeries IS NULL')
            ->andWhere(
                'time.competitionRound = :round OR (time.competitionRound IS NULL AND time.puzzlingType = :category AND time.puzzle IN ('
                . 'SELECT IDENTITY(roundPuzzle.puzzle) FROM ' . CompetitionRoundPuzzle::class . ' roundPuzzle WHERE roundPuzzle.round = :round'
                . '))',
            )
            ->setParameter('competition', $round->competition->id->toString())
            ->setParameter('round', $round->id->toString())
            ->setParameter('category', PuzzlingType::from($round->category->value))
            ->orderBy('time.id')
            ->getQuery()
            ->getResult();
    }
}
