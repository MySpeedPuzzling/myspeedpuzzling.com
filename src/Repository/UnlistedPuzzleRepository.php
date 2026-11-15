<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\UnlistedOwnership;
use SpeedPuzzling\Web\Entity\UnlistedPairing;
use SpeedPuzzling\Web\Entity\UnlistedPuzzle;
use SpeedPuzzling\Web\Entity\UnlistedResult;
use SpeedPuzzling\Web\Exceptions\UnlistedPuzzleNotFound;
use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\Unlisted\UnlistedOwnershipKind;
use SpeedPuzzling\Web\Value\Unlisted\UnlistedPairingState;

/**
 * Write side of a player's unlisted puzzles, their results, their owned / wish marks and their pairings
 * (docs/features/data-import/unlisted-puzzles.md). Every lookup that starts from a request takes the player: somebody
 * else's unlisted puzzle is "not found".
 */
readonly final class UnlistedPuzzleRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(UnlistedPuzzle $puzzle): void
    {
        $this->entityManager->persist($puzzle);
    }

    public function delete(UnlistedPuzzle $puzzle): void
    {
        $this->entityManager->remove($puzzle);
    }

    public function saveResult(UnlistedResult $result): void
    {
        $this->entityManager->persist($result);
    }

    public function deleteResult(UnlistedResult $result): void
    {
        $this->entityManager->remove($result);
    }

    public function saveOwnership(UnlistedOwnership $ownership): void
    {
        $this->entityManager->persist($ownership);
    }

    public function deleteOwnership(UnlistedOwnership $ownership): void
    {
        $this->entityManager->remove($ownership);
    }

    public function savePairing(UnlistedPairing $pairing): void
    {
        $this->entityManager->persist($pairing);
    }

    public function find(string $unlistedPuzzleId): null|UnlistedPuzzle
    {
        return Uuid::isValid($unlistedPuzzleId) ? $this->entityManager->find(UnlistedPuzzle::class, $unlistedPuzzleId) : null;
    }

    /**
     * @throws UnlistedPuzzleNotFound
     */
    public function getOwnedBy(string $unlistedPuzzleId, string $playerId): UnlistedPuzzle
    {
        $puzzle = $this->find($unlistedPuzzleId);

        if ($puzzle === null || Uuid::isValid($playerId) === false || $puzzle->isOwnedBy($playerId) === false) {
            throw new UnlistedPuzzleNotFound();
        }

        return $puzzle;
    }

    public function ofPlayerByKey(Player $player, string $puzzleKey): null|UnlistedPuzzle
    {
        // A puzzle made earlier in this unit of work (two rows of one puzzle in one transaction) is not saved yet
        foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof UnlistedPuzzle && $entity->player === $player && $entity->puzzleKey === $puzzleKey) {
                return $entity;
            }
        }

        return $this->entityManager->getRepository(UnlistedPuzzle::class)->findOneBy(['player' => $player, 'puzzleKey' => $puzzleKey]);
    }

    public function findResult(string $resultId): null|UnlistedResult
    {
        return Uuid::isValid($resultId) ? $this->entityManager->find(UnlistedResult::class, $resultId) : null;
    }

    public function findOwnership(string $ownershipId): null|UnlistedOwnership
    {
        return Uuid::isValid($ownershipId) ? $this->entityManager->find(UnlistedOwnership::class, $ownershipId) : null;
    }

    public function resultOfRow(UuidInterface $importRowId): null|UnlistedResult
    {
        foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof UnlistedResult && $entity->importRowId->equals($importRowId)) {
                return $entity;
            }
        }

        return $this->entityManager->getRepository(UnlistedResult::class)->findOneBy(['importRowId' => $importRowId]);
    }

    /**
     * The player's unlisted result a row with the same key made - in any of their imports.
     */
    public function resultOfSourceKey(Player $player, string $sourceKey): null|UnlistedResult
    {
        foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof UnlistedResult && $entity->player === $player && $entity->sourceKey === $sourceKey) {
                return $entity;
            }
        }

        return $this->entityManager->getRepository(UnlistedResult::class)->findOneBy(['player' => $player, 'sourceKey' => $sourceKey], ['id' => 'ASC']);
    }

    /**
     * A result the unlisted puzzle already has that says the same: day (at its precision), time and solo/pair/team.
     */
    public function equalResultOf(UnlistedPuzzle $puzzle, null|string $solvedOn, null|int $seconds, PuzzlingType $type): null|UnlistedResult
    {
        foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
            if (
                $entity instanceof UnlistedResult
                && $entity->unlistedPuzzle === $puzzle
                && $entity->solvedOn === $solvedOn
                && $entity->secondsToSolve === $seconds
                && $entity->puzzlingType === $type
            ) {
                return $entity;
            }
        }

        return $this->entityManager->getRepository(UnlistedResult::class)->findOneBy([
            'unlistedPuzzle' => $puzzle,
            'solvedOn' => $solvedOn,
            'secondsToSolve' => $seconds,
            'puzzlingType' => $type,
        ], ['id' => 'ASC']);
    }

    public function ownershipOf(UnlistedPuzzle $puzzle, UnlistedOwnershipKind $kind): null|UnlistedOwnership
    {
        foreach ($this->entityManager->getUnitOfWork()->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof UnlistedOwnership && $entity->unlistedPuzzle === $puzzle && $entity->kind === $kind) {
                return $entity;
            }
        }

        return $this->entityManager->getRepository(UnlistedOwnership::class)->findOneBy(['unlistedPuzzle' => $puzzle, 'kind' => $kind]);
    }

    /**
     * @return list<UnlistedResult>
     */
    public function resultsOf(UnlistedPuzzle $puzzle): array
    {
        return $this->entityManager->getRepository(UnlistedResult::class)->findBy(['unlistedPuzzle' => $puzzle], ['id' => 'ASC']);
    }

    /**
     * @return list<UnlistedOwnership>
     */
    public function ownershipsOf(UnlistedPuzzle $puzzle): array
    {
        return $this->entityManager->getRepository(UnlistedOwnership::class)->findBy(['unlistedPuzzle' => $puzzle], ['id' => 'ASC']);
    }

    /**
     * Whether nothing hangs on the unlisted puzzle any more - what the row being handled is about to remove counts
     * as gone.
     */
    public function holdsNothing(UnlistedPuzzle $puzzle): bool
    {
        $unitOfWork = $this->entityManager->getUnitOfWork();

        foreach ([...$this->resultsOf($puzzle), ...$this->ownershipsOf($puzzle)] as $held) {
            if ($unitOfWork->isScheduledForDelete($held) === false) {
                return false;
            }
        }

        return true;
    }

    public function findPairing(string $pairingId): null|UnlistedPairing
    {
        return Uuid::isValid($pairingId) ? $this->entityManager->find(UnlistedPairing::class, $pairingId) : null;
    }

    public function findPairingByRequestId(string $requestId): null|UnlistedPairing
    {
        return Uuid::isValid($requestId) ? $this->entityManager->getRepository(UnlistedPairing::class)->findOneBy(['requestId' => $requestId]) : null;
    }

    public function workingPairingOf(UnlistedPuzzle $puzzle): null|UnlistedPairing
    {
        return $this->entityManager->getRepository(UnlistedPairing::class)->findOneBy([
            'unlistedPuzzle' => $puzzle,
            'state' => [UnlistedPairingState::Running, UnlistedPairingState::Undoing],
        ]);
    }

    /**
     * What a pairing has still to do, the results first: while it runs the results and marks it took that are
     * neither converted nor left with a reason; while it is being undone the ones it converted and no reason keeps.
     *
     * @return list<array{kind: string, id: string}> kind: `result` / `ownership`
     */
    public function pendingOfPairing(string $pairingId, bool $undoing, int $limit): array
    {
        $pending = [];

        foreach (['result' => UnlistedResult::class, 'ownership' => UnlistedOwnership::class] as $kind => $class) {
            /** @var list<array{id: UuidInterface}> $rows */
            $rows = $this->entityManager->createQueryBuilder()
                ->select('subject.id')
                ->from($class, 'subject')
                ->where('subject.pairingId = :pairingId')
                ->andWhere($undoing ? 'subject.convertedAt IS NOT NULL' : 'subject.convertedAt IS NULL')
                ->andWhere('subject.keptReason IS NULL')
                ->setParameter('pairingId', $pairingId)
                ->orderBy('subject.id')
                ->setMaxResults($limit)
                ->getQuery()
                ->getArrayResult();

            foreach ($rows as $row) {
                $pending[] = ['kind' => $kind, 'id' => $row['id']->toString()];
            }
        }

        return array_slice($pending, 0, $limit);
    }

    /**
     * Pairings the worker left standing (a killed worker, a lost job, adding paused) - for the sweeper. While adding
     * is paused only Undos are continued.
     *
     * @return list<string>
     */
    public function pairingIdsStandingSince(DateTimeImmutable $since, int $limit, bool $onlyUndos = false): array
    {
        /** @var list<array{id: UuidInterface}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('pairing.id')
            ->from(UnlistedPairing::class, 'pairing')
            ->where('pairing.state IN (:working)')
            ->andWhere('pairing.lastActivityAt < :since')
            ->setParameter('working', $onlyUndos ? [UnlistedPairingState::Undoing] : [UnlistedPairingState::Running, UnlistedPairingState::Undoing])
            ->setParameter('since', $since)
            ->orderBy('pairing.lastActivityAt')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): string => $row['id']->toString(), $rows);
    }

    /**
     * The unlisted puzzles the daily look asks the catalogue about next: not paired, the longest not asked first.
     *
     * @return list<UnlistedPuzzle>
     */
    public function nextToMatch(DateTimeImmutable $checkedBefore, int $limit): array
    {
        /** @var list<UnlistedPuzzle> $puzzles */
        $puzzles = $this->entityManager->createQueryBuilder()
            ->select('puzzle')
            // Never asked comes first (Postgres sorts NULL last)
            ->addSelect('CASE WHEN puzzle.matchCheckedAt IS NULL THEN 0 ELSE 1 END AS HIDDEN asked')
            ->from(UnlistedPuzzle::class, 'puzzle')
            ->where('puzzle.pairedPuzzleId IS NULL')
            ->andWhere('puzzle.matchCheckedAt IS NULL OR puzzle.matchCheckedAt < :checkedBefore')
            ->setParameter('checkedBefore', $checkedBefore)
            ->orderBy('asked', 'ASC')
            ->addOrderBy('puzzle.matchCheckedAt', 'ASC')
            ->addOrderBy('puzzle.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $puzzles;
    }
}
