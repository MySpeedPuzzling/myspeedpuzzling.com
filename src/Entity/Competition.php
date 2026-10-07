<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\RegistrationAvailability;
use SpeedPuzzling\Web\Value\RoundTimezone;

#[Entity]
#[Table]
#[UniqueConstraint(columns: ['series_id', 'slug'])]
class Competition
{
    /**
     * The zone the registration window was typed in (an IANA zone, like competition_round.timezone) - the settings page
     * shows the window in it again, the event page names it. Null until the organiser saves registration settings.
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $registrationTimezone = null;

    /**
     * @param Collection<int, Player> $maintainers
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Column]
        public string $name,
        #[Column(nullable: true)]
        public null|string $slug,
        #[Column(nullable: true)]
        public null|string $shortcut,
        #[Column(nullable: true)]
        public null|string $logo,
        #[Column(type: Types::TEXT, nullable: true)]
        public null|string $description,
        #[Column(nullable: true)]
        public null|string $link,
        #[Column(nullable: true)]
        public null|string $registrationLink,
        #[Column(nullable: true)]
        public null|string $resultsLink,
        #[Column(nullable: true)]
        public null|string $location,
        #[Column(nullable: true)]
        public null|string $locationCountryCode,
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $dateFrom,
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $dateTo,
        #[ManyToOne]
        public null|Tag $tag,
        #[Column(options: ['default' => false])]
        public bool $isOnline = false,
        #[ManyToOne]
        public null|CompetitionSeries $series = null,
        #[Immutable]
        #[ManyToOne]
        public null|Player $addedByPlayer = null,
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $approvedAt = null,
        #[ManyToOne]
        public null|Player $approvedByPlayer = null,
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $rejectedAt = null,
        #[ManyToOne]
        public null|Player $rejectedByPlayer = null,
        #[Column(type: Types::TEXT, nullable: true)]
        public null|string $rejectionReason = null,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $createdAt = null,
        /**
         * @var Collection<int, Player>
         */
        #[ManyToMany(targetEntity: Player::class)]
        #[JoinTable(name: 'competition_maintainer')]
        public Collection $maintainers = new ArrayCollection(),
        #[Column(options: ['default' => false])]
        public bool $registrationManaged = false,
        #[Column(nullable: true)]
        public null|int $capacity = null,
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $registrationOpensAt = null,
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $registrationClosesAt = null,
        #[Column(nullable: true)]
        public null|string $entryFeeText = null,
        #[Column(type: Types::TEXT, nullable: true)]
        public null|string $paymentInstructions = null,
    ) {
        $this->locationCountryCode = self::normalizeCountryCode($locationCountryCode);
    }

    public function approve(Player $approvedBy, DateTimeImmutable $approvedAt): void
    {
        $this->approvedAt = $approvedAt;
        $this->approvedByPlayer = $approvedBy;
    }

    public function reject(Player $rejectedBy, DateTimeImmutable $rejectedAt, string $reason): void
    {
        $this->rejectedAt = $rejectedAt;
        $this->rejectedByPlayer = $rejectedBy;
        $this->rejectionReason = $reason;
    }

    public function isApproved(): bool
    {
        return $this->approvedAt !== null;
    }

    public function isRejected(): bool
    {
        return $this->rejectedAt !== null;
    }

    public function edit(
        string $name,
        null|string $slug,
        null|string $shortcut,
        null|string $logo,
        null|string $description,
        null|string $link,
        null|string $registrationLink,
        null|string $resultsLink,
        null|string $location,
        null|string $locationCountryCode,
        null|DateTimeImmutable $dateFrom,
        null|DateTimeImmutable $dateTo,
        bool $isOnline,
    ): void {
        $this->name = $name;
        $this->slug = $slug;
        $this->shortcut = $shortcut;
        $this->logo = $logo;
        $this->description = $description;
        $this->link = $link;
        $this->registrationLink = $registrationLink;
        $this->resultsLink = $resultsLink;
        $this->location = $location;
        $this->locationCountryCode = self::normalizeCountryCode($locationCountryCode);
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo;
        $this->isOnline = $isOnline;
    }

    /**
     * Managed registration (docs/features/competitions-management/registration.md). The external registration link is
     * kept as it is: hidden while registration is managed, back when it is switched off. Instants are UTC, typed in
     * $timezone on the settings page.
     */
    public function changeRegistrationSettings(
        bool $registrationManaged,
        null|int $capacity,
        null|DateTimeImmutable $registrationOpensAt,
        null|DateTimeImmutable $registrationClosesAt,
        string $timezone,
        null|string $entryFeeText,
        null|string $paymentInstructions,
    ): void {
        $this->registrationManaged = $registrationManaged;
        $this->capacity = $capacity;
        $this->registrationOpensAt = $registrationOpensAt;
        $this->registrationClosesAt = $registrationClosesAt;
        $this->registrationTimezone = $timezone;
        $this->entryFeeText = $entryFeeText;
        $this->paymentInstructions = $paymentInstructions;
    }

    /**
     * Whether a new registration can be made now - only for an event that manages registration and is publicly visible
     * (IsCompetitionPubliclyVisible, the caller asks it).
     */
    public function registrationAvailability(DateTimeImmutable $now, bool $publiclyVisible): RegistrationAvailability
    {
        if ($publiclyVisible === false) {
            return RegistrationAvailability::NotPublic;
        }

        return RegistrationAvailability::ofWindow($now, $this->registrationOpensAt, $this->registrationClosesAt, $this->endsAt());
    }

    /**
     * The zone of the registration window: the one saved with the settings, else the event's (or its series') country's.
     */
    public function registrationZone(): string
    {
        return RoundTimezone::resolve($this->registrationTimezone, $this->locationCountryCode, $this->series?->locationCountryCode);
    }

    /**
     * The end of the event's last day in its zone - registration without a closing time closes then, and nothing about
     * paying is sent after it. Null for an event without dates.
     */
    public function endsAt(): null|DateTimeImmutable
    {
        return RegistrationAvailability::eventEndsAt($this->dateFrom, $this->dateTo, $this->registrationZone());
    }

    public function isOver(DateTimeImmutable $now): bool
    {
        $endsAt = $this->endsAt();

        return $endsAt !== null && $now >= $endsAt;
    }

    private static function normalizeCountryCode(null|string $countryCode): null|string
    {
        return $countryCode !== null ? strtolower($countryCode) : null;
    }
}
