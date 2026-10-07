<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\InvalidRegistrationSettings;
use SpeedPuzzling\Web\Message\ChangeCompetitionRegistrationSettings;
use SpeedPuzzling\Web\Repository\CompetitionParticipantRepository;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Services\CompetitionRegistrationMailer;
use SpeedPuzzling\Web\Value\RegistrationEmail;
use SpeedPuzzling\Web\Value\RoundTimezone;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Switching management on: every row already "going" holds a spot (reserved) - the settings page says how many.
 * Switching it off: the event is an open "I'm going" list again, so the waitlist becomes going too (the page says how
 * many) and gets the "a spot opened up" e-mail the waitlist was promised. No waitlisted row stays behind on an event
 * that does not manage registration (CompetitionParticipantGoing) - not even the row of somebody who left the waitlist,
 * since joining again restores it. Statuses, payments and check-ins stay on the rows and count again when management
 * is switched back on.
 */
#[AsMessageHandler]
readonly final class ChangeCompetitionRegistrationSettingsHandler
{
    public function __construct(
        private CompetitionRepository $competitionRepository,
        private CompetitionParticipantRepository $participantRepository,
        private CompetitionRegistrationMailer $registrationMailer,
    ) {
    }

    /**
     * @throws InvalidRegistrationSettings
     */
    public function __invoke(ChangeCompetitionRegistrationSettings $message): void
    {
        $this->validate($message);

        $competition = $this->competitionRepository->get($message->competitionId);
        $switchedOff = $competition->registrationManaged && $message->registrationManaged === false;

        $competition->changeRegistrationSettings(
            registrationManaged: $message->registrationManaged,
            capacity: $message->capacity,
            registrationOpensAt: $message->registrationOpensAt,
            registrationClosesAt: $message->registrationClosesAt,
            timezone: $message->timezone,
            entryFeeText: $message->entryFeeText,
            paymentInstructions: $message->paymentInstructions,
        );

        if ($switchedOff) {
            foreach ($this->participantRepository->waitlistOf($message->competitionId, includeDeleted: true) as $participant) {
                $participant->promoteFromWaitlist();

                // Sent through the transactional queue inside this transaction - a rollback sends nothing
                if ($participant->isDeleted() === false) {
                    $this->registrationMailer->send($participant, RegistrationEmail::Promoted);
                }
            }
        }
    }

    private function validate(ChangeCompetitionRegistrationSettings $message): void
    {
        if ($message->capacity !== null && ($message->capacity < 1 || $message->capacity > ChangeCompetitionRegistrationSettings::CAPACITY_MAX)) {
            throw new InvalidRegistrationSettings('Capacity out of range');
        }

        if (
            $message->registrationOpensAt !== null
            && $message->registrationClosesAt !== null
            && $message->registrationClosesAt <= $message->registrationOpensAt
        ) {
            throw new InvalidRegistrationSettings('Registration closes before it opens');
        }

        if (RoundTimezone::isValid($message->timezone) === false) {
            throw new InvalidRegistrationSettings('Unknown time zone');
        }

        if ($message->entryFeeText !== null && mb_strlen($message->entryFeeText) > ChangeCompetitionRegistrationSettings::ENTRY_FEE_MAX_LENGTH) {
            throw new InvalidRegistrationSettings('Entry fee too long');
        }

        if ($message->paymentInstructions !== null && mb_strlen($message->paymentInstructions) > ChangeCompetitionRegistrationSettings::PAYMENT_INSTRUCTIONS_MAX_LENGTH) {
            throw new InvalidRegistrationSettings('Payment instructions too long');
        }
    }
}
