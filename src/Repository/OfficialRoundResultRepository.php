<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OfficialRoundResult;
use SpeedPuzzling\Web\Exceptions\OfficialRoundResultNotFound;

readonly final class OfficialRoundResultRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws OfficialRoundResultNotFound
     */
    public function get(string $resultId): OfficialRoundResult
    {
        if (!Uuid::isValid($resultId)) {
            throw new OfficialRoundResultNotFound();
        }

        $result = $this->entityManager->find(OfficialRoundResult::class, $resultId);

        return $result ?? throw new OfficialRoundResultNotFound();
    }

    public function find(string $resultId): null|OfficialRoundResult
    {
        if (!Uuid::isValid($resultId)) {
            return null;
        }

        return $this->entityManager->find(OfficialRoundResult::class, $resultId);
    }

    public function findByRoundAndParticipant(string $roundId, string $participantId): null|OfficialRoundResult
    {
        return $this->entityManager->getRepository(OfficialRoundResult::class)->findOneBy([
            'round' => $roundId,
            'participant' => $participantId,
        ]);
    }

    public function findByRoundAndTeam(string $roundId, string $teamId): null|OfficialRoundResult
    {
        return $this->entityManager->getRepository(OfficialRoundResult::class)->findOneBy([
            'round' => $roundId,
            'team' => $teamId,
        ]);
    }

    public function save(OfficialRoundResult $result): void
    {
        $this->entityManager->persist($result);
    }

    public function delete(OfficialRoundResult $result): void
    {
        $this->entityManager->remove($result);
    }
}
