<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OnlineRound;

/**
 * The rounds that use online timing - of an event, or of every edition of a series - by their names, for the changes
 * that would leave online timing where it cannot work (OnlineTimingInUse: an event or a series changed to in person, an
 * edition moved into an in-person series). One statement each.
 */
readonly final class GetRoundsUsingOnlineTiming
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<string> round names, by start
     */
    public function ofCompetition(string $competitionId): array
    {
        if (Uuid::isValid($competitionId) === false) {
            return [];
        }

        return $this->names('IDENTITY(round.competition) = :id', $competitionId);
    }

    /**
     * @return list<string> round names of the series' editions, by start
     */
    public function ofSeries(string $seriesId): array
    {
        if (Uuid::isValid($seriesId) === false) {
            return [];
        }

        return $this->names('IDENTITY(event.series) = :id', $seriesId);
    }

    /**
     * @return list<string>
     */
    private function names(string $condition, string $id): array
    {
        /** @var list<array{name: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('round.name')
            ->from(OnlineRound::class, 'online_round')
            ->innerJoin('online_round.round', 'round')
            ->innerJoin('round.competition', 'event')
            ->where($condition)
            ->setParameter('id', strtolower($id))
            ->orderBy('round.startsAt', 'ASC')
            ->addOrderBy('round.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'name');
    }
}
