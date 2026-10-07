<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CompetitionParticipantNotFound;
use SpeedPuzzling\Web\Exceptions\RegistrationNotManaged;
use SpeedPuzzling\Web\Message\UnmarkParticipantPaid;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Value\RegistrationStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class UnmarkParticipantPaidHandler
{
    public function __construct(
        private CompetitionParticipantRepository $participantRepository,
    ) {
    }

    /**
     * @throws CompetitionParticipantNotFound
     * @throws RegistrationNotManaged
     */
    public function __invoke(UnmarkParticipantPaid $message): void
    {
        $participant = $this->participantRepository->getActiveOfCompetition($message->competitionId, $message->participantId);

        if ($participant->competition->registrationManaged === false) {
            throw new RegistrationNotManaged();
        }

        // Only a paid row goes back to reserved - a waitlisted one never jumps the queue this way
        if ($participant->effectiveRegistrationStatus() !== RegistrationStatus::Paid) {
            return;
        }

        $participant->unmarkPaid();
    }
}
