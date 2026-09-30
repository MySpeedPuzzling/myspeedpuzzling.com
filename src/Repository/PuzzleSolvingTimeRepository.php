<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
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
}
