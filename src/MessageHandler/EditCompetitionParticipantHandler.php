<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Exceptions\OrganizerNoteTooLong;
use SpeedPuzzling\Web\Message\EditCompetitionParticipant;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\ClaimedResultReverter;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class EditCompetitionParticipantHandler
{
    public function __construct(
        private CompetitionParticipantRepository $participantRepository,
        private PlayerRepository $playerRepository,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private ClaimedResultReverter $claimedResultReverter,
    ) {
    }

    public function __invoke(EditCompetitionParticipant $message): void
    {
        // The column's length - refused before anything changes (the participants page checks it first)
        if ($message->organizerNote !== null && mb_strlen($message->organizerNote) > CompetitionParticipant::ORGANIZER_NOTE_MAX_LENGTH) {
            throw new OrganizerNoteTooLong();
        }

        $participant = $this->participantRepository->get($message->participantId);

        $participant->updateName($message->name);
        $participant->updateCountry($message->country);
        $participant->updateExternalId($message->externalId);

        if ($message->changeOrganizerNote) {
            $participant->updateOrganizerNote($message->organizerNote);
        }

        // Sync player connection
        if ($message->playerId !== null) {
            $currentPlayerId = $participant->player?->id->toString();

            if ($currentPlayerId !== $message->playerId) {
                if ($currentPlayerId !== null) {
                    $this->claimedResultReverter->revertForPlayerInCompetition(
                        $currentPlayerId,
                        $participant->competition->id->toString(),
                    );
                }

                $player = $this->playerRepository->get($message->playerId);
                $participant->disconnect();
                $participant->connect($player, $this->clock->now());
            }
        } else {
            if ($participant->player !== null) {
                $this->claimedResultReverter->revertForPlayerInCompetition(
                    $participant->player->id->toString(),
                    $participant->competition->id->toString(),
                );
            }

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

        foreach (array_unique($message->roundIds) as $roundId) {
            if (!in_array($roundId, $existingRoundIds, true) && Uuid::isValid($roundId)) {
                $round = $this->entityManager->find(CompetitionRound::class, $roundId);

                // Only rounds of the participant's own competition
                if ($round === null || !$round->competition->id->equals($participant->competition->id)) {
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
