<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\PuzzlingTeamMember;
use SpeedPuzzling\Web\Exceptions\PuzzleSolvingTimeNotFound;
use SpeedPuzzling\Web\Value\PuzzlingType;

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
     * Solo times with seconds of the player whose prediction was not evaluated yet, plus the listed ones.
     * Locked for the rest of the transaction: an edit of one of them waits until the new predictions are
     * committed, and two reconstructions of one player never interleave.
     *
     * @param list<string> $alsoTimeIds
     * @return list<PuzzleSolvingTime>
     */
    public function findForPredictionOfPlayer(string $playerId, array $alsoTimeIds = []): array
    {
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('time')
            ->from(PuzzleSolvingTime::class, 'time')
            ->where('time.player = :playerId')
            ->andWhere('time.puzzlingType = :solo')
            ->andWhere('time.secondsToSolve IS NOT NULL')
            ->setParameter('playerId', $playerId)
            ->setParameter('solo', PuzzlingType::Solo);

        if ($alsoTimeIds === []) {
            $queryBuilder->andWhere('time.predictable IS NULL');
        } else {
            $queryBuilder
                ->andWhere('time.predictable IS NULL OR time.id IN (:alsoTimeIds)')
                ->setParameter('alsoTimeIds', $alsoTimeIds);
        }

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
