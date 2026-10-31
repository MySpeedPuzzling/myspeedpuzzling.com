<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\PuzzlingTeamMember;
use SpeedPuzzling\Web\Entity\SolvingTimeGrave;
use SpeedPuzzling\Web\Entity\SolvingTimeTombstone;
use SpeedPuzzling\Web\Entity\SolvingTimePredictionRecord;
use SpeedPuzzling\Web\Exceptions\PuzzleSolvingTimeNotFound;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\CandidatePredictionCalculator;
use SpeedPuzzling\Web\Value\PredictorKind;
use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\RemovedResultSnapshot;
use SpeedPuzzling\Web\Value\SolvingTimeDeletedVia;
use SpeedPuzzling\Web\Value\TimePredictionSource;

readonly final class PuzzleSolvingTimeRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SolvingTimeGraveyardRepository $graveyardRepository,
        private ClockInterface $clock,
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
     * The listed puzzles' results that carry a finished-puzzle photo of a public player - the photos the image pipeline
     * may analyse (docs/features/insights/finished-photos.md; a private player's photo never leaves the app). One
     * statement.
     *
     * @param list<string> $puzzleIds
     *
     * @return list<PuzzleSolvingTime>
     */
    public function findWithFinishedPhotoOfPuzzles(array $puzzleIds): array
    {
        $puzzleIds = array_values(array_unique(array_map(strtolower(...), array_filter($puzzleIds, Uuid::isValid(...)))));

        if ($puzzleIds === []) {
            return [];
        }

        /** @var list<PuzzleSolvingTime> $times */
        $times = $this->entityManager->createQueryBuilder()
            ->select('time', 'player', 'puzzle')
            ->from(PuzzleSolvingTime::class, 'time')
            ->join('time.player', 'player')
            ->join('time.puzzle', 'puzzle')
            ->where('time.puzzle IN (:puzzleIds)')
            ->andWhere('time.finishedPuzzlePhoto IS NOT NULL')
            ->andWhere('player.isPrivate = false')
            ->setParameter('puzzleIds', $puzzleIds)
            ->getQuery()
            ->getResult();

        return $times;
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
     * The one way a solving time is deleted (only account deletion erases times without it): PuzzleSolvingTimeDeleted
     * recalculates statistics and insights; the time is buried whole in the graveyard (disaster recovery, never read)
     * and every registered member gets a tombstone for API clients (docs/features/api/solving-time-sync.md).
     */
    public function delete(PuzzleSolvingTime $solvingTime, SolvingTimeDeletedVia $via): void
    {
        $now = $this->clock->now();

        $this->graveyardRepository->bury(new SolvingTimeGrave(
            id: Uuid::uuid7(),
            solvingTimeId: $solvingTime->id,
            playerId: $solvingTime->player->id,
            deletedAt: $now,
            deletedVia: $via,
            snapshot: RemovedResultSnapshot::of($solvingTime)->toArray(),
        ));

        foreach ($solvingTime->memberPlayerIds() as $memberPlayerId) {
            $this->graveyardRepository->addTombstone(new SolvingTimeTombstone(
                id: Uuid::uuid7(),
                solvingTimeId: $solvingTime->id,
                playerId: Uuid::fromString($memberPlayerId),
                deletedAt: $now,
            ));
        }

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
        // The shown prediction (PREDICTION_SHOWN_MODEL): with $recomputeReconstructed the reconstructed ones computed no
        // later than the shown model's fit (default $recomputeReconstructed) or made by another model than the shown one
        null|DateTimeImmutable $recomputeShownBefore = null,
        int $shownModelVersion = TimePredictionCalculator::MODEL_VERSION,
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
            $conditions[] = '(time.predictionSource = :reconstructed AND (time.predictionComputedAt <= :shownFittedAt OR time.predictionModelVersion IS NULL OR time.predictionModelVersion <> :shownModelVersion))';
            $queryBuilder
                ->setParameter('shownFittedAt', $recomputeShownBefore ?? $recomputeReconstructed)
                ->setParameter('shownModelVersion', $shownModelVersion);
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
