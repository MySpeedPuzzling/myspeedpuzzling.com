<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\RegistrationStatus;

readonly final class RegistrationOverview
{
    public function __construct(
        public bool $registrationManaged,
        public null|int $capacity,
        public null|DateTimeImmutable $registrationOpensAt,
        public null|DateTimeImmutable $registrationClosesAt,
        public null|string $entryFeeText,
        public null|string $paymentInstructions,
        public int $spotsTaken,
        public int $waitlistedCount,
        public null|string $playerParticipantId,
        public null|RegistrationStatus $playerStatus,
        public null|int $playerWaitlistPosition,
    ) {
    }

    public function isFull(): bool
    {
        return $this->capacity !== null && $this->spotsTaken >= $this->capacity;
    }

    public function isOpen(DateTimeImmutable $now): bool
    {
        if ($this->registrationManaged === false) {
            return false;
        }

        if ($this->registrationOpensAt !== null && $now < $this->registrationOpensAt) {
            return false;
        }

        if ($this->registrationClosesAt !== null && $now > $this->registrationClosesAt) {
            return false;
        }

        return true;
    }

    public function opensInFuture(DateTimeImmutable $now): bool
    {
        return $this->registrationOpensAt !== null && $now < $this->registrationOpensAt;
    }
}
