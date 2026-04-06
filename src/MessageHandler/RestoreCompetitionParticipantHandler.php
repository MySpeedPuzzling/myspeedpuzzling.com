<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

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

    public function __invoke(RestoreCompetitionParticipant $message): void
    {
        $participant = $this->participantRepository->get($message->participantId);
        $participant->restore();
    }
}
