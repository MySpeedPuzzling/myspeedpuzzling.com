<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CompetitionParticipantNotFound;
use SpeedPuzzling\Web\Exceptions\RegistrationNotManaged;
use SpeedPuzzling\Web\Message\PromoteParticipantFromWaitlist;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Services\CompetitionRegistrationMailer;
use SpeedPuzzling\Web\Value\RegistrationEmail;
use SpeedPuzzling\Web\Value\RegistrationStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The organiser decides who gets a free spot (the management page suggests the first in line) - promotion is never
 * automatic. Promoting above the capacity is the organiser's call too, like adding a participant by hand.
 */
#[AsMessageHandler]
readonly final class PromoteParticipantFromWaitlistHandler
{
    public function __construct(
        private CompetitionParticipantRepository $participantRepository,
        private CompetitionRegistrationMailer $registrationMailer,
    ) {
    }

    /**
     * @throws CompetitionParticipantNotFound
     * @throws RegistrationNotManaged
     */
    public function __invoke(PromoteParticipantFromWaitlist $message): void
    {
        $participant = $this->participantRepository->getActiveOfCompetition($message->competitionId, $message->participantId);

        if ($participant->competition->registrationManaged === false) {
            throw new RegistrationNotManaged();
        }

        if ($participant->effectiveRegistrationStatus() !== RegistrationStatus::Waitlisted) {
            // Promoted already (a second click, another device) - no change, no second e-mail
            return;
        }

        $participant->promoteFromWaitlist();

        $this->registrationMailer->send($participant, RegistrationEmail::Promoted);
    }
}
