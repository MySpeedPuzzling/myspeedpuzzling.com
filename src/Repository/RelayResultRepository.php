<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\RelayResult;

readonly final class RelayResultRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The relay team's results, one per puzzle (solo-and-relays.md §2). The first one (the lowest id) is the relay's
     * own: claims, releases and removals of the team go through it, so one lock serialises them
     * (ClaimEventResultSlot::lockKeyOf()).
     *
     * @return list<PuzzleSolvingTime>
     */
    public function resultsOf(RelayResult $relayResult): array
    {
        /** @var list<PuzzleSolvingTime> $results */
        $results = $this->entityManager->createQueryBuilder()
            ->select('time')
            ->from(PuzzleSolvingTime::class, 'time')
            ->where('time.relayResult = :relay')
            ->setParameter('relay', $relayResult->id)
            ->orderBy('time.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $results;
    }

    public function save(RelayResult $relayResult): void
    {
        $this->entityManager->persist($relayResult);
    }

    /**
     * Only after its results (PuzzleSolvingTimeRepository::delete()) - nothing removes them with it.
     */
    public function delete(RelayResult $relayResult): void
    {
        $this->entityManager->remove($relayResult);
    }
}
