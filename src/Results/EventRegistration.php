<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\RegistrationAvailability;
use SpeedPuzzling\Web\Value\RegistrationStatus;

/**
 * The registration card of an event that manages registration (docs/features/competitions-management/registration.md),
 * shown in place of the plain "I'm going" buttons (templates/_event_attendance.html.twig). Read with the attendance in
 * one statement (GetEventAttendance::forEvent()).
 */
readonly final class EventRegistration
{
    public function __construct(
        public RegistrationAvailability $availability,
        public null|int $capacity,
        // Rows holding a spot: everybody going (reserved, paid, and rows without a status)
        public int $spotsTaken,
        public int $waitlistedCount,
        public null|DateTimeImmutable $opensAt,
        public null|DateTimeImmutable $closesAt,
        // The zone the organiser typed the window in - the card shows the instants in it
        public string $timezone,
        public null|string $entryFeeText,
        public null|string $paymentInstructions,
        // The viewer's registration: null = the viewer holds no row of the event
        public null|RegistrationStatus $playerStatus = null,
        public null|int $playerWaitlistPosition = null,
        // Cancelling removes the viewer's own registration - an organiser's row is only let go of
        public bool $playerSelfJoined = false,
        // The organiser's list still has names nobody picked - picking yours works even when registration is closed
        public bool $hasListedNames = false,
    ) {
    }

    public function isFull(): bool
    {
        return $this->capacity !== null && $this->spotsTaken >= $this->capacity;
    }

    public function isOpen(): bool
    {
        return $this->availability === RegistrationAvailability::Open;
    }

    public function isRegistered(): bool
    {
        return $this->playerStatus !== null;
    }

    public function isWaitlisted(): bool
    {
        return $this->playerStatus === RegistrationStatus::Waitlisted;
    }

    public function isPaid(): bool
    {
        return $this->playerStatus === RegistrationStatus::Paid;
    }

    /**
     * Width of the "spots taken" bar, 0-100.
     */
    public function takenPercent(): int
    {
        if ($this->capacity === null || $this->capacity <= 0) {
            return 0;
        }

        return (int) min(100, round($this->spotsTaken / $this->capacity * 100));
    }
}
