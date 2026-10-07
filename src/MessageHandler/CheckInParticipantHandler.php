<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantNotFound;
use SpeedPuzzling\Web\Exceptions\ParticipantIsWaitlisted;
use SpeedPuzzling\Web\Exceptions\RegistrationNotManaged;
use SpeedPuzzling\Web\Message\CheckInParticipant;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Value\RegistrationStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class CheckInParticipantHandler
{
    public function __construct(
        private CompetitionParticipantRepository $participantRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CompetitionParticipantNotFound
     * @throws RegistrationNotManaged
     * @throws ParticipantIsWaitlisted
     */
    public function __invoke(CheckInParticipant $message): void
    {
        $participant = $this->participantRepository->getActiveOfCompetition($message->competitionId, $message->participantId);

        if ($participant->competition->registrationManaged === false) {
            throw new RegistrationNotManaged();
        }

        if ($participant->effectiveRegistrationStatus() === RegistrationStatus::Waitlisted) {
            throw new ParticipantIsWaitlisted();
        }

        if ($participant->checkedInAt !== null) {
            return;
        }

        $participant->checkIn($this->clock->now());
    }
}
