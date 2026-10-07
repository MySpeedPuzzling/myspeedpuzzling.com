<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;
use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\CompetitionParticipantsLock;

/**
 * The organiser's registration settings of one event or edition (docs/features/competitions-management/registration.md)
 * - their own message, so editing the event (EditCompetition, the internal API) never touches them. Takes turns with
 * the registrations: switching management off moves the waitlist to "going".
 */
readonly final class ChangeCompetitionRegistrationSettings implements SerializedByLock
{
    public const int ENTRY_FEE_MAX_LENGTH = 255;
    public const int PAYMENT_INSTRUCTIONS_MAX_LENGTH = 2000;
    public const int CAPACITY_MAX = 100000;

    public function __construct(
        public string $competitionId,
        public bool $registrationManaged,
        public null|int $capacity,
        // Instants (UTC) - the organiser typed them in $timezone
        public null|DateTimeImmutable $registrationOpensAt,
        public null|DateTimeImmutable $registrationClosesAt,
        public string $timezone,
        public null|string $entryFeeText,
        public null|string $paymentInstructions,
    ) {
    }

    public function lockKey(): string
    {
        return CompetitionParticipantsLock::key($this->competitionId);
    }
}
