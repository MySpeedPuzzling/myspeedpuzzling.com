<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr\Join;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\SuspiciousTimeCase;
use SpeedPuzzling\Web\Exceptions\SuspiciousTimeCaseNotFound;
use SpeedPuzzling\Web\Value\SuspiciousTimeCaseStatus;

readonly final class SuspiciousTimeCaseRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(SuspiciousTimeCase $case): void
    {
        $this->entityManager->persist($case);
    }

    /**
     * @throws SuspiciousTimeCaseNotFound
     */
    public function get(string $caseId): SuspiciousTimeCase
    {
        if (!Uuid::isValid($caseId)) {
            throw new SuspiciousTimeCaseNotFound();
        }

        return $this->entityManager->find(SuspiciousTimeCase::class, $caseId) ?? throw new SuspiciousTimeCaseNotFound();
    }

    /**
     * The case read again with its row locked until the transaction ends (docs/features/suspicious-time-review.md,
     * "Moderator queue"): a decision about a case waits for the scan, the edit re-check or another decision that holds
     * it, and then sees what they wrote - nobody overwrites a decision taken meanwhile.
     *
     * @throws SuspiciousTimeCaseNotFound
     */
    public function getForUpdate(string $caseId): SuspiciousTimeCase
    {
        $case = $this->get($caseId);
        $this->lock($case);

        return $case;
    }

    /**
     * Locks the case's row until the transaction ends and reads it again - only the case, never its time's row (an
     * edit saving the time waits for nobody but the case's lock).
     */
    public function lock(SuspiciousTimeCase $case): void
    {
        $this->entityManager->refresh($case, LockMode::PESSIMISTIC_WRITE);
    }

    /**
     * Locks the cases' rows until the transaction ends and reads them again - the scan, before it writes to cases it
     * read a while ago (docs/features/suspicious-time-review.md, "Checks and versions"). In the order of their ids, so
     * two lockers never wait for each other in a circle. Only the cases' rows: their times are not locked (with
     * $freshTimes they are read again too).
     *
     * @param list<string> $caseIds
     * @return list<SuspiciousTimeCase>
     */
    public function lockForDecision(array $caseIds, bool $freshTimes = false): array
    {
        if ($caseIds === []) {
            return [];
        }

        $cases = $this->locked('c.id IN (:ids)', $caseIds);

        if ($freshTimes) {
            foreach ($cases as $case) {
                $this->entityManager->refresh($case->time);
            }
        }

        return $cases;
    }

    /**
     * Like findByTimes(), the rows locked until the transaction ends and read again (lockForDecision()).
     *
     * @param list<string> $timeIds
     * @return array<string, SuspiciousTimeCase> keyed by time id
     */
    public function lockByTimes(array $timeIds): array
    {
        if ($timeIds === []) {
            return [];
        }

        $byTime = [];

        foreach ($this->locked('IDENTITY(c.time) IN (:ids)', $timeIds) as $case) {
            $byTime[$case->time->id->toString()] = $case;
        }

        return $byTime;
    }

    /**
     * @param list<string> $ids
     * @return list<SuspiciousTimeCase>
     */
    private function locked(string $condition, array $ids): array
    {
        /** @var list<SuspiciousTimeCase> $cases */
        $cases = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(SuspiciousTimeCase::class, 'c')
            ->where($condition)
            ->setParameter('ids', $ids)
            ->orderBy('c.id')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return $cases;
    }

    public function findByTime(string $timeId): null|SuspiciousTimeCase
    {
        if (!Uuid::isValid($timeId)) {
            return null;
        }

        return $this->entityManager->getRepository(SuspiciousTimeCase::class)->findOneBy(['time' => $timeId]);
    }

    /**
     * @param list<string> $timeIds
     * @return array<string, SuspiciousTimeCase> keyed by time id
     */
    public function findByTimes(array $timeIds): array
    {
        if ($timeIds === []) {
            return [];
        }

        /** @var list<SuspiciousTimeCase> $cases */
        $cases = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(SuspiciousTimeCase::class, 'c')
            ->where('IDENTITY(c.time) IN (:timeIds)')
            ->setParameter('timeIds', $timeIds)
            ->getQuery()
            ->getResult();

        $byTime = [];

        foreach ($cases as $case) {
            $byTime[$case->time->id->toString()] = $case;
        }

        return $byTime;
    }

    /**
     * Marked cases whose time is not flagged any more - unmarked by SQL (the scan's flag reconciliation).
     *
     * @return list<SuspiciousTimeCase>
     */
    public function findMarkedWithoutFlag(): array
    {
        /** @var list<SuspiciousTimeCase> $cases */
        $cases = $this->entityManager->createQueryBuilder()
            ->select('c', 't')
            ->from(SuspiciousTimeCase::class, 'c')
            ->innerJoin('c.time', 't')
            ->where('c.status = :marked')
            ->andWhere('t.suspicious = false')
            ->setParameter('marked', SuspiciousTimeCaseStatus::Marked)
            ->getQuery()
            ->getResult();

        return $cases;
    }

    /**
     * Flagged times without a marked case - flagged by SQL (the scan's flag reconciliation).
     *
     * @return list<PuzzleSolvingTime>
     */
    public function findFlaggedTimesWithoutMarkedCase(): array
    {
        /** @var list<PuzzleSolvingTime> $times */
        $times = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(PuzzleSolvingTime::class, 't')
            ->leftJoin(SuspiciousTimeCase::class, 'c', Join::WITH, 'c.time = t')
            ->where('t.suspicious = true')
            ->andWhere('c.id IS NULL OR c.status <> :marked')
            ->setParameter('marked', SuspiciousTimeCaseStatus::Marked)
            ->getQuery()
            ->getResult();

        return $times;
    }

    /**
     * Pending cases whose time can no longer be judged - without a time now. The scan only checks times with seconds,
     * so nothing else would ever close them.
     *
     * @return list<SuspiciousTimeCase>
     */
    public function findPendingNoLongerEligible(): array
    {
        /** @var list<SuspiciousTimeCase> $cases */
        $cases = $this->entityManager->createQueryBuilder()
            ->select('c', 't')
            ->from(SuspiciousTimeCase::class, 'c')
            ->innerJoin('c.time', 't')
            ->where('c.status = :pending')
            ->andWhere('t.suspicious = false')
            ->andWhere('t.secondsToSolve IS NULL OR t.secondsToSolve <= 0')
            ->setParameter('pending', SuspiciousTimeCaseStatus::Pending)
            ->getQuery()
            ->getResult();

        return $cases;
    }

    /**
     * Every mark in force - what the notice run tells.
     *
     * @return list<SuspiciousTimeCase>
     */
    public function findMarkedOfFlaggedTimes(): array
    {
        /** @var list<SuspiciousTimeCase> $cases */
        $cases = $this->entityManager->createQueryBuilder()
            ->select('c', 't')
            ->from(SuspiciousTimeCase::class, 'c')
            ->innerJoin('c.time', 't')
            ->where('c.status = :marked')
            ->andWhere('t.suspicious = true')
            ->andWhere('c.markedAt IS NOT NULL')
            ->setParameter('marked', SuspiciousTimeCaseStatus::Marked)
            ->getQuery()
            ->getResult();

        return $cases;
    }
}
