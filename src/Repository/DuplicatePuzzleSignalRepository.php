<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DuplicatePuzzleSignal;
use SpeedPuzzling\Web\Exceptions\DuplicatePuzzleSignalNotFound;

readonly final class DuplicatePuzzleSignalRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws DuplicatePuzzleSignalNotFound
     */
    public function get(string $signalId): DuplicatePuzzleSignal
    {
        if (Uuid::isValid($signalId) === false) {
            throw new DuplicatePuzzleSignalNotFound();
        }

        return $this->entityManager->find(DuplicatePuzzleSignal::class, $signalId)
            ?? throw new DuplicatePuzzleSignalNotFound();
    }

    public function save(DuplicatePuzzleSignal $signal): void
    {
        $this->entityManager->persist($signal);
    }

    public function delete(DuplicatePuzzleSignal $signal): void
    {
        $this->entityManager->remove($signal);
    }

    /**
     * Every stored signal, in any status (a few hundred) - keyed by DuplicatePuzzleSignal::key().
     *
     * @return array<string, DuplicatePuzzleSignal>
     */
    public function allByPair(): array
    {
        /** @var list<DuplicatePuzzleSignal> $signals */
        $signals = $this->entityManager->getRepository(DuplicatePuzzleSignal::class)->findAll();

        $byPair = [];

        foreach ($signals as $signal) {
            $byPair[$signal->pairKey()] = $signal;
        }

        return $byPair;
    }
}
