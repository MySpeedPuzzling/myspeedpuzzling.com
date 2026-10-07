<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CompetitionParticipantNotFound;
use SpeedPuzzling\Web\Exceptions\RegistrationNotManaged;
use SpeedPuzzling\Web\Message\UndoParticipantCheckIn;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class UndoParticipantCheckInHandler
{
    public function __construct(
        private CompetitionParticipantRepository $participantRepository,
    ) {
    }

    /**
     * @throws CompetitionParticipantNotFound
     * @throws RegistrationNotManaged
     */
    public function __invoke(UndoParticipantCheckIn $message): void
    {
        $participant = $this->participantRepository->getActiveOfCompetition($message->competitionId, $message->participantId);

        if ($participant->competition->registrationManaged === false) {
            throw new RegistrationNotManaged();
        }

        $participant->undoCheckIn();
    }
}
