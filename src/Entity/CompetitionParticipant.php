<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\ParticipantSource;
use SpeedPuzzling\Web\Value\RegistrationStatus;

#[Entity]
class CompetitionParticipant
{
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $connectedAt = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[ManyToOne]
    public null|Player $player = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $remoteId = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $externalId = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $deletedAt = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, nullable: true, enumType: RegistrationStatus::class)]
    public null|RegistrationStatus $registrationStatus = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $registeredAt = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $paidAt = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $checkedInAt = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $organizerNote = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column]
        public string $name,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(nullable: true)]
        public null|string $country,
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public Competition $competition,
        #[Column(type: Types::STRING, enumType: ParticipantSource::class, options: ['default' => 'imported'])]
        public ParticipantSource $source = ParticipantSource::Imported,
    ) {
    }

    public function connect(Player $player, DateTimeImmutable $connectedAt): void
    {
        $this->player = $player;
        $this->connectedAt = $connectedAt;
    }

    public function disconnect(): void
    {
        $this->player = null;
        $this->connectedAt = null;
    }

    public function updateRemoteId(string $remoteId): void
    {
        $this->remoteId = $remoteId;
    }

    public function updateExternalId(null|string $externalId): void
    {
        $this->externalId = $externalId;
    }

    public function updateName(string $name): void
    {
        $this->name = $name;
    }

    public function updateCountry(null|string $country): void
    {
        $this->country = $country;
    }

    public function softDelete(DateTimeImmutable $deletedAt): void
    {
        $this->deletedAt = $deletedAt;
    }

    public function restore(): void
    {
        $this->deletedAt = null;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function register(RegistrationStatus $status, DateTimeImmutable $registeredAt): void
    {
        $this->registrationStatus = $status;
        $this->registeredAt = $registeredAt;
        // A (re-)registration starts fresh: not paid, not checked in
        $this->paidAt = null;
        $this->checkedInAt = null;
    }

    public function markPaid(DateTimeImmutable $paidAt): void
    {
        $this->registrationStatus = RegistrationStatus::Paid;
        $this->paidAt = $paidAt;
    }

    public function unmarkPaid(): void
    {
        $this->registrationStatus = RegistrationStatus::Reserved;
        $this->paidAt = null;
    }

    public function promoteFromWaitlist(): void
    {
        $this->registrationStatus = RegistrationStatus::Reserved;
    }

    public function checkIn(DateTimeImmutable $checkedInAt): void
    {
        $this->checkedInAt = $checkedInAt;
    }

    public function undoCheckIn(): void
    {
        $this->checkedInAt = null;
    }

    public function updateOrganizerNote(null|string $organizerNote): void
    {
        $this->organizerNote = $organizerNote;
    }
}
