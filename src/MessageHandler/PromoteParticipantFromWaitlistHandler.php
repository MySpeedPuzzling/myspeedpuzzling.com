<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantNotFound;
use SpeedPuzzling\Web\Exceptions\RegistrationNotManaged;
use SpeedPuzzling\Web\Message\PromoteParticipantFromWaitlist;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Services\CompetitionRegistrationMailer;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlinePersonalLinks;
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
        private OnlinePersonalLinks $onlinePersonalLinks,
        private ClockInterface $clock,
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

        // A player who joined by themselves gets their personal link with the spot - on the event page
        // (docs/features/online-competitions/README.md §5.4); an organiser's row gets it on the personal links page
        $this->onlinePersonalLinks->giveOwnLink($participant, $this->clock->now());

        $this->registrationMailer->send($participant, RegistrationEmail::Promoted);
    }
}
