<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OnlineRound;
use SpeedPuzzling\Web\Exceptions\OnlineRoundNotFound;

readonly final class OnlineRoundRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws OnlineRoundNotFound
     */
    public function get(string $onlineRoundId): OnlineRound
    {
        if (!Uuid::isValid($onlineRoundId)) {
            throw new OnlineRoundNotFound();
        }

        $onlineRound = $this->entityManager->find(OnlineRound::class, $onlineRoundId);

        return $onlineRound ?? throw new OnlineRoundNotFound();
    }

    /**
     * The online timing of a round - null for a round that does not use it.
     */
    public function ofRound(string $roundId): null|OnlineRound
    {
        if (!Uuid::isValid($roundId)) {
            return null;
        }

        /** @var null|OnlineRound $onlineRound */
        $onlineRound = $this->entityManager->createQueryBuilder()
            ->select('online_round', 'round')
            ->from(OnlineRound::class, 'online_round')
            ->innerJoin('online_round.round', 'round')
            ->where('round.id = :roundId')
            ->setParameter('roundId', $roundId)
            ->getQuery()
            ->getOneOrNullResult();

        return $onlineRound;
    }

    /**
     * Every round of the competition that uses online timing, by start - with the rounds and their puzzles in the same
     * statement (a participant's state names the round's piece count).
     *
     * @return list<OnlineRound>
     */
    public function ofCompetition(string $competitionId): array
    {
        if (!Uuid::isValid($competitionId)) {
            return [];
        }

        /** @var list<OnlineRound> $onlineRounds */
        $onlineRounds = $this->entityManager->createQueryBuilder()
            ->select('online_round', 'round', 'round_puzzle', 'puzzle')
            ->from(OnlineRound::class, 'online_round')
            ->innerJoin('online_round.round', 'round')
            ->leftJoin('round.roundPuzzles', 'round_puzzle')
            ->leftJoin('round_puzzle.puzzle', 'puzzle')
            ->where('round.competition = :competitionId')
            ->setParameter('competitionId', $competitionId)
            ->orderBy('round.startsAt', 'ASC')
            ->addOrderBy('round.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $onlineRounds;
    }

    public function save(OnlineRound $onlineRound): void
    {
        $this->entityManager->persist($onlineRound);
    }

    public function delete(OnlineRound $onlineRound): void
    {
        $this->entityManager->remove($onlineRound);
    }
}
