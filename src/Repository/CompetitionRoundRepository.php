<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;

readonly final class CompetitionRoundRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws CompetitionRoundNotFound
     */
    public function get(string $roundId): CompetitionRound
    {
        if (!Uuid::isValid($roundId)) {
            throw new CompetitionRoundNotFound();
        }

        $round = $this->entityManager->find(CompetitionRound::class, $roundId);

        return $round ?? throw new CompetitionRoundNotFound();
    }

    /**
     * The round with its competition in one statement - for a reader that needs the competition's settings and must not
     * pay a statement of its own for them (RecordRoundResultsHandler: time proposals on or off).
     *
     * @throws CompetitionRoundNotFound
     */
    public function getWithCompetition(string $roundId): CompetitionRound
    {
        if (!Uuid::isValid($roundId)) {
            throw new CompetitionRoundNotFound();
        }

        $round = $this->entityManager->createQueryBuilder()
            ->select('r', 'c')
            ->from(CompetitionRound::class, 'r')
            ->innerJoin('r.competition', 'c')
            ->where('r.id = :id')
            ->setParameter('id', strtolower($roundId))
            ->getQuery()
            ->getOneOrNullResult();

        return $round instanceof CompetitionRound ? $round : throw new CompetitionRoundNotFound();
    }

    /**
     * The competition's rounds, by start
     *
     * @return list<CompetitionRound>
     */
    public function ofCompetition(Competition $competition): array
    {
        /** @var list<CompetitionRound> $rounds */
        $rounds = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(CompetitionRound::class, 'r')
            ->where('r.competition = :competition')
            ->setParameter('competition', $competition)
            ->addOrderBy('r.startsAt')
            ->addOrderBy('r.id')
            ->getQuery()
            ->getResult();

        return $rounds;
    }

    public function save(CompetitionRound $round): void
    {
        $this->entityManager->persist($round);
    }

    public function delete(CompetitionRound $round): void
    {
        $this->entityManager->remove($round);
    }
}
