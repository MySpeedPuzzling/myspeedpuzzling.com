<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundPuzzleNotFound;

readonly final class CompetitionRoundPuzzleRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function get(string $id): CompetitionRoundPuzzle
    {
        if (!Uuid::isValid($id)) {
            throw new CompetitionRoundPuzzleNotFound();
        }

        $roundPuzzle = $this->entityManager->find(CompetitionRoundPuzzle::class, $id);

        if ($roundPuzzle === null) {
            throw new CompetitionRoundPuzzleNotFound();
        }

        return $roundPuzzle;
    }

    /**
     * Read from the rows, never from CompetitionRound::$roundPuzzles - a round loaded before its puzzles were set in the
     * same request holds a stale collection.
     *
     * @return list<string>
     */
    public function puzzleIdsOfRound(CompetitionRound $round): array
    {
        /** @var list<string> $puzzleIds */
        $puzzleIds = $this->entityManager->createQuery(
            'SELECT IDENTITY(roundPuzzle.puzzle) FROM ' . CompetitionRoundPuzzle::class . ' roundPuzzle WHERE roundPuzzle.round = :round',
        )->setParameter('round', $round->id)->getSingleColumnResult();

        return array_map(static fn (string $id): string => strtolower($id), $puzzleIds);
    }

    public function save(CompetitionRoundPuzzle $roundPuzzle): void
    {
        $this->entityManager->persist($roundPuzzle);
    }

    public function delete(CompetitionRoundPuzzle $roundPuzzle): void
    {
        $this->entityManager->remove($roundPuzzle);
    }
}
