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
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use LogicException;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Exceptions\OrganizationOnEdition;
use SpeedPuzzling\Web\Value\RegistrationAvailability;
use SpeedPuzzling\Web\Value\RoundTimezone;

#[Entity]
#[Table]
#[UniqueConstraint(columns: ['series_id', 'slug'])]
class Competition
{
    /**
     * When it entered the approval queue (docs/features/organizations/README.md "Approval"): created published, first
     * published, approved, or created or published by an admin or the internal API. The admins' "submitted" e-mail
     * goes out only while it is null, so going back to draft and publishing again never e-mails them twice. Rows from
     * before the column are null.
     */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $submittedAt = null;

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
        // One-time events only (docs/features/organizations/README.md): an edition belongs to its series' organization
        // and never has its own - assignOrganization()
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public null|Organization $organization = null,
        // Only its team sees a draft (IsCompetitionPubliclyVisible) - publish() / unpublish()
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(options: ['default' => false])]
        public bool $isDraft = false,
        // "Who can enter" ("Residents of the state", "21+") - an edition without its own shows its series' -
        // changeEligibility()
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: 120, nullable: true)]
        public null|string $eligibility = null,
    ) {
        if ($series !== null && $organization !== null) {
            throw new OrganizationOnEdition();
        }

        $this->locationCountryCode = self::normalizeCountryCode($locationCountryCode);
    }

    /**
     * Moves a one-time event into an organization (or out of it, null). The caller checks who may
     * (AssignEventToOrganization) and runs OrganizationApprovalPolicy afterwards.
     *
     * @throws OrganizationOnEdition
     */
    public function assignOrganization(null|Organization $organization): void
    {
        if ($organization !== null && $this->series !== null) {
            throw new OrganizationOnEdition();
        }

        $this->organization = $organization;
    }

    public function publish(): void
    {
        $this->isDraft = false;
    }

    public function unpublish(): void
    {
        $this->isDraft = true;
    }

    /**
     * Hidden as a draft: its own flag, or its series is a draft
     */
    public function isHiddenAsDraft(): bool
    {
        return $this->isDraft || ($this->series !== null && $this->series->isDraft);
    }

    /**
     * The PHP mirror of IsCompetitionPubliclyVisible::SQL_CONDITION - for handlers deciding before the flush
     * (VisibilityParityTest keeps them equal)
     */
    public function isPubliclyVisible(): bool
    {
        if ($this->rejectedAt !== null || $this->isDraft) {
            return false;
        }

        if ($this->series === null) {
            return $this->approvedAt !== null;
        }

        return $this->series->isPubliclyVisible();
    }

    public function changeEligibility(null|string $eligibility): void
    {
        $this->eligibility = $eligibility;
    }

    /**
     * Moves an edition to another series (MoveEditionToSeries). The caller checks the slug is free in the target. A
     * place equal to the old series' (copied when the edition was added) takes the target's; a place the organiser
     * changed stays (docs/features/organizations/README.md, P21). Online follows the target series.
     */
    public function moveToSeries(CompetitionSeries $target, string $slug): void
    {
        $oldSeries = $this->series;

        if ($oldSeries === null) {
            throw new LogicException('Only an edition moves to another series.');
        }

        if ($this->location === $oldSeries->location) {
            $this->location = $target->location;
        }

        if ($this->locationCountryCode === $oldSeries->locationCountryCode) {
            $this->locationCountryCode = $target->locationCountryCode;
        }

        $this->series = $target;
        $this->slug = $slug;
        $this->isOnline = $target->isOnline;
    }

    public function approve(Player $approvedBy, DateTimeImmutable $approvedAt): void
    {
        $this->approvedAt = $approvedAt;
        $this->approvedByPlayer = $approvedBy;
        // An approved item is past the queue - publishing it later e-mails nobody
        $this->markSubmitted($approvedAt);
    }

    /**
     * It entered the approval queue (or skipped it) - kept at the first time
     */
    public function markSubmitted(DateTimeImmutable $submittedAt): void
    {
        $this->submittedAt ??= $submittedAt;
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
