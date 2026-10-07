<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\ParticipantsSheet;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\RegistrationStatus;

/**
 * A participant's managed registration (docs/features/competitions-management/registration.md) - only on an event
 * that manages registration. A row without a stored status holds a spot: it reads as reserved.
 */
readonly final class ParticipantsSheetRegistration implements \JsonSerializable
{
    public function __construct(
        public RegistrationStatus $status,
        public null|DateTimeImmutable $registeredAt,
        // Kept after a cancelled registration too - "paid on …, before the registration was cancelled"
        public null|DateTimeImmutable $paidAt,
        public null|DateTimeImmutable $checkedInAt,
    ) {
    }

    /**
     * @return array{status: string, registeredAt: null|string, paidAt: null|string, checkedInAt: null|string}
     */
    public function jsonSerialize(): array
    {
        return [
            'status' => $this->status->value,
            'registeredAt' => $this->registeredAt?->format(DateTimeImmutable::ATOM),
            'paidAt' => $this->paidAt?->format(DateTimeImmutable::ATOM),
            'checkedInAt' => $this->checkedInAt?->format(DateTimeImmutable::ATOM),
        ];
    }
}
