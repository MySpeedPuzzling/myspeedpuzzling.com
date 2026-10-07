<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CompetitionParticipantNotFound;
use SpeedPuzzling\Web\Message\RestoreCompetitionParticipant;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class RestoreCompetitionParticipantHandler
{
    public function __construct(
        private CompetitionParticipantRepository $participantRepository,
    ) {
    }

    /**
     * @throws CompetitionParticipantNotFound a participant of another event
     */
    public function __invoke(RestoreCompetitionParticipant $message): void
    {
        $participant = $this->participantRepository->getOfCompetition($message->competitionId, $message->participantId);
        $participant->restore();

        // A row that left the waitlist of a managed event comes back to an event that no longer manages registration:
        // no waitlist there, it is going (docs/features/competitions-management/registration.md)
        if ($participant->competition->registrationManaged === false) {
            $participant->leaveWaitlistOfUnmanagedEvent();
        }
    }
}
