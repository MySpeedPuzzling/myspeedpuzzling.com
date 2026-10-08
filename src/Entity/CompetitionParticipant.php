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
    public const int ORGANIZER_NOTE_MAX_LENGTH = 255;

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

    /** Private to the event's maintainers, never shown publicly */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: self::ORGANIZER_NOTE_MAX_LENGTH, nullable: true)]
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

    /**
     * A self-joined player found on the organizer's list: from now on the row is the organizer's,
     * so leaving the event disconnects it instead of deleting it.
     */
    public function markAsImported(): void
    {
        $this->source = ParticipantSource::Imported;
    }

    /**
     * A new registration to an event with managed registration (docs/features/competitions-management/registration.md):
     * reserved, or waitlisted when the event is full. A registration made again after cancelling starts fresh - not paid,
     * not checked in, at the end of the queue - but keeps when it was paid before: the organiser's record of a payment
     * they hold is never wiped by the player (the participants sheet shows it, "Mark paid" confirms it again).
     */
    public function register(RegistrationStatus $status, DateTimeImmutable $registeredAt): void
    {
        $this->registrationStatus = $status;
        $this->registeredAt = $registeredAt;
        $this->checkedInAt = null;
    }

    /**
     * A waitlist exists only on an event that manages registration (CompetitionParticipantGoing): a waitlisted row that
     * comes back on an event that does not - joining again, the organiser's restore - holds a spot like every other
     * "I'm going".
     */
    public function leaveWaitlistOfUnmanagedEvent(): void
    {
        if ($this->registrationStatus === RegistrationStatus::Waitlisted) {
            $this->registrationStatus = RegistrationStatus::Reserved;
        }
    }

    /**
     * Rows without a status (on the list before registration was managed, imported, "I'm going" before) hold a spot -
     * they read as reserved.
     */
    public function effectiveRegistrationStatus(): RegistrationStatus
    {
        return $this->registrationStatus ?? RegistrationStatus::Reserved;
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

    /**
     * The one "is going" rule (CompetitionParticipantGoing) on the entity: not removed from the event, not waiting on
     * its waitlist.
     */
    public function isGoing(): bool
    {
        return $this->isDeleted() === false && $this->registrationStatus !== RegistrationStatus::Waitlisted;
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
