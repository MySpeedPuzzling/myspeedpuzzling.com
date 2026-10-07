<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantNotFound;
use SpeedPuzzling\Web\Exceptions\ParticipantIsWaitlisted;
use SpeedPuzzling\Web\Exceptions\RegistrationNotManaged;
use SpeedPuzzling\Web\Message\MarkParticipantPaid;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Services\CompetitionRegistrationMailer;
use SpeedPuzzling\Web\Value\RegistrationEmail;
use SpeedPuzzling\Web\Value\RegistrationStatus;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class MarkParticipantPaidHandler
{
    public function __construct(
        private CompetitionParticipantRepository $participantRepository,
        private ClockInterface $clock,
        private CompetitionRegistrationMailer $registrationMailer,
    ) {
    }

    /**
     * @throws CompetitionParticipantNotFound
     * @throws RegistrationNotManaged
     * @throws ParticipantIsWaitlisted
     */
    public function __invoke(MarkParticipantPaid $message): void
    {
        $participant = $this->participantRepository->getActiveOfCompetition($message->competitionId, $message->participantId);

        if ($participant->competition->registrationManaged === false) {
            throw new RegistrationNotManaged();
        }

        $status = $participant->effectiveRegistrationStatus();

        if ($status === RegistrationStatus::Paid) {
            // Already paid (a second click, another device) - no change, no second e-mail
            return;
        }

        if ($status === RegistrationStatus::Waitlisted && $message->promoteFromWaitlist === false) {
            throw new ParticipantIsWaitlisted();
        }

        $participant->markPaid($this->clock->now());

        $this->registrationMailer->send($participant, RegistrationEmail::Paid);
    }
}
