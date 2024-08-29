<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\WjpcParticipant;
use SpeedPuzzling\Web\Message\SyncWjpcIndividualParticipants;
use SpeedPuzzling\Web\Query\GetWjpcParticipants;
use SpeedPuzzling\Web\Repository\WjpcParticipantRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class SyncWjpcIndividualParticipantsHandler
{
    public function __construct(
        private WjpcParticipantRepository $participantRepository,
        private GetWjpcParticipants $getWjpcParticipants,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncWjpcIndividualParticipants $message): void
    {
        $existingParticipants = $this->getWjpcParticipants->mappingForPairing();

        /** @var array<string, bool> $participantNamesFromSync */
        $participantNamesFromSync = [];

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
                    new WjpcParticipant(
                        Uuid::uuid7(),
                        name: $name,
                        country: $country,
                        rounds: $rounds,
                        year2023Rank: $rank,
                    ),
                );
            } else {
                $existingParticipant = $this->participantRepository->get($existingParticipantId);
                $existingParticipant->update(
                    $country,
                    $group,
                );
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
