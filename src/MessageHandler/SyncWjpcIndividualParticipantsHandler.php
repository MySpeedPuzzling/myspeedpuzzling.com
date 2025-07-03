<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Message\SyncWjpcIndividualParticipants;
use SpeedPuzzling\Web\Query\GetCompetitionParticipants;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class SyncWjpcIndividualParticipantsHandler
{
    public function __construct(
        private CompetitionParticipantRepository $participantRepository,
        private GetCompetitionParticipants $getCompetitionParticipants,
        private LoggerInterface $logger,
        private CompetitionRepository $competitionRepository,
    ) {
    }

    public function __invoke(SyncWjpcIndividualParticipants $message): void
    {
        $existingParticipants = $this->getCompetitionParticipants->mappingForPairing('');

        /** @var array<string, bool> $participantNamesFromSync */
        $participantNamesFromSync = [];

        $competition = $this->competitionRepository->get('');

        foreach ($message->individuals as $participant) {
            $name = $participant['name'];
            $country = $participant['country'];
            $rank = $participant['rank'];
            $group = $participant['group'];
            $participantNamesFromSync[$name] = true;
            $existingParticipantId = $existingParticipants[$name] ?? null;

            if ($existingParticipantId === null) {
                $this->logger->info('Adding participant (new in csv) - ' . $name);

                $rounds = $group !== null ? [$group] : [];
                $this->participantRepository->save(
                    new CompetitionParticipant(
                        Uuid::uuid7(),
                        name: $name,
                        country: $country,
                        competition: $competition,
                    ),
                );
            } else {
                $existingParticipant = $this->participantRepository->get($existingParticipantId);
            }
        }

        foreach ($existingParticipants as $name => $id) {
            if (isset($participantNamesFromSync[$name]) === false) {
                $participantToDelete = $this->participantRepository->get($id);

                if ($participantToDelete->player !== null) {
                    $this->logger->notice('Deleting connected participant (missing in csv) - ' . $participantToDelete->name);
                } else {
                    $this->participantRepository->delete($participantToDelete);
                }
            }
        }
    }
}
