<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\RoundResult;
use SpeedPuzzling\Web\Exceptions\RoundResultNotFound;

readonly final class RoundResultRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws RoundResultNotFound
     */
    public function get(string $resultId): RoundResult
    {
        if (!Uuid::isValid($resultId)) {
            throw new RoundResultNotFound();
        }

        $result = $this->entityManager->find(RoundResult::class, $resultId);

        return $result ?? throw new RoundResultNotFound();
    }

    public function find(string $resultId): null|RoundResult
    {
        if (!Uuid::isValid($resultId)) {
            return null;
        }

        return $this->entityManager->find(RoundResult::class, $resultId);
    }

    public function findByRoundAndParticipant(string $roundId, string $participantId): null|RoundResult
    {
        return $this->entityManager->getRepository(RoundResult::class)->findOneBy([
            'round' => $roundId,
            'participant' => $participantId,
        ]);
    }

    public function findByRoundAndTeam(string $roundId, string $teamId): null|RoundResult
    {
        return $this->entityManager->getRepository(RoundResult::class)->findOneBy([
            'round' => $roundId,
            'team' => $teamId,
        ]);
    }

    public function save(RoundResult $result): void
    {
        $this->entityManager->persist($result);
    }

    public function delete(RoundResult $result): void
    {
        $this->entityManager->remove($result);
    }
}
