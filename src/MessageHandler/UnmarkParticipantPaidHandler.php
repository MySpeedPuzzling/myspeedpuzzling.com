<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\UnmarkParticipantPaid;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class UnmarkParticipantPaidHandler
{
    public function __construct(
        private CompetitionParticipantRepository $participantRepository,
    ) {
    }

    public function __invoke(UnmarkParticipantPaid $message): void
    {
        $participant = $this->participantRepository->get($message->participantId);
        $participant->unmarkPaid();
    }
}
