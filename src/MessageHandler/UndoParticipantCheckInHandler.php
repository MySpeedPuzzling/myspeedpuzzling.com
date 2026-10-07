<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

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

    public function __invoke(UndoParticipantCheckIn $message): void
    {
        $participant = $this->participantRepository->get($message->participantId);
        $participant->undoCheckIn();
    }
}
