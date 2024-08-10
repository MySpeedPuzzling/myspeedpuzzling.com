<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
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
    ) {
    }

    public function __invoke(SyncWjpcIndividualParticipants $message): void
    {
        $existingParticipants = $this->getWjpcParticipants->mappingForPairing();

        /** @var array<string, bool> $participantNamesFromSync */
        $participantNamesFromSync = [];

        foreach ($message->individuals as $participant) {
            $name = $participant['name'];
            $participantNamesFromSync[$name] = true;

            if (isset($existingParticipants[$name]) === false) {
                $this->participantRepository->save(
                    new WjpcParticipant(
                        Uuid::uuid7(),
                        $name,
                        $participant['country'],
                        [],
                        $participant['rank'],
                    ),
                );
            }
        }

        foreach ($existingParticipants as $name => $id) {
            if (isset($participantNamesFromSync[$name]) === false) {
                $participantToDelete = $this->participantRepository->get($id);

                $this->participantRepository->delete($participantToDelete);
            }
        }
    }
}
