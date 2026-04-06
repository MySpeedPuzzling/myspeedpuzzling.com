<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Message\EditCompetitionParticipant;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class EditCompetitionParticipantHandler
{
    public function __construct(
        private CompetitionParticipantRepository $participantRepository,
        private PlayerRepository $playerRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(EditCompetitionParticipant $message): void
    {
        $participant = $this->participantRepository->get($message->participantId);

        $participant->updateName($message->name);
        $participant->updateCountry($message->country);
        $participant->updateExternalId($message->externalId);

        // Sync player connection
        if ($message->playerId !== null) {
            $currentPlayerId = $participant->player?->id->toString();

            if ($currentPlayerId !== $message->playerId) {
                $player = $this->playerRepository->get($message->playerId);
                $participant->disconnect();
                $participant->connect($player, $this->clock->now());
            }
        } else {
            $participant->disconnect();
        }

        // Sync round assignments
        $this->syncRoundAssignments($message);
    }

    private function syncRoundAssignments(EditCompetitionParticipant $message): void
    {
        /** @var array<CompetitionParticipantRound> $existingRounds */
        $existingRounds = $this->entityManager
            ->getRepository(CompetitionParticipantRound::class)
            ->findBy(['participant' => $message->participantId]);

        $existingRoundIds = [];

        foreach ($existingRounds as $participantRound) {
            $roundId = $participantRound->round->id->toString();

            if (!in_array($roundId, $message->roundIds, true)) {
                $this->entityManager->remove($participantRound);
            } else {
                $existingRoundIds[] = $roundId;
            }
        }

        $participant = $this->participantRepository->get($message->participantId);

        foreach ($message->roundIds as $roundId) {
            if (!in_array($roundId, $existingRoundIds, true)) {
                $round = $this->entityManager->find(CompetitionRound::class, $roundId);

                if ($round === null) {
                    continue;
                }

                $participantRound = new CompetitionParticipantRound(
                    id: Uuid::uuid7(),
                    participant: $participant,
                    round: $round,
                );

                $this->entityManager->persist($participantRound);
            }
        }
    }
}
