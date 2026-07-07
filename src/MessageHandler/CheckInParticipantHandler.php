<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\CheckInParticipant;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class CheckInParticipantHandler
{
    public function __construct(
        private CompetitionParticipantRepository $participantRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CheckInParticipant $message): void
    {
        $participant = $this->participantRepository->get($message->participantId);
        $participant->checkIn($this->clock->now());
    }
}
