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
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

#[Entity]
class CompetitionSeries
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
     * @param Collection<int, Player> $maintainers
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Column]
        public string $name,
        #[Column(unique: true, nullable: true)]
        public null|string $slug,
        #[Column(nullable: true)]
        public null|string $logo,
        #[Column(type: Types::TEXT, nullable: true)]
        public null|string $description,
        #[Column(nullable: true)]
        public null|string $link,
        #[Column(options: ['default' => false])]
        public bool $isOnline = false,
        #[Column(nullable: true)]
        public null|string $location = null,
        #[Column(nullable: true)]
        public null|string $locationCountryCode = null,
        #[Column(unique: true, nullable: true)]
        public null|string $shortcut = null,
        #[ManyToOne]
        public null|Tag $tag = null,
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
        #[JoinTable(name: 'competition_series_maintainer')]
        public Collection $maintainers = new ArrayCollection(),
        // The organization running it (docs/features/organizations/README.md) - its editions are under it too.
        // assignOrganization()
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public null|Organization $organization = null,
        // A draft series hides itself and every edition (IsSeriesPubliclyVisible) - publish() / unpublish()
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(options: ['default' => false])]
        public bool $isDraft = false,
        // "Who can enter" - shown by editions without their own
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: 120, nullable: true)]
        public null|string $eligibility = null,
        // "When it happens" ("Third Wednesday of the month, 6:45 pm") - free text, each date is still its own edition
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: 160, nullable: true)]
        public null|string $schedule = null,
    ) {
        $this->locationCountryCode = self::normalizeCountryCode($locationCountryCode);
    }

    /**
     * Moves the series into an organization (or out of it, null). The caller checks who may (AssignEventToOrganization)
     * and runs OrganizationApprovalPolicy afterwards.
     */
    public function assignOrganization(null|Organization $organization): void
    {
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
     * The PHP mirror of IsSeriesPubliclyVisible::SQL_CONDITION (VisibilityParityTest keeps them equal)
     */
    public function isPubliclyVisible(): bool
    {
        return $this->approvedAt !== null && $this->rejectedAt === null && $this->isDraft === false;
    }

    public function changeEligibilityAndSchedule(null|string $eligibility, null|string $schedule): void
    {
        $this->eligibility = $eligibility;
        $this->schedule = $schedule;
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
        null|string $logo,
        null|string $description,
        null|string $link,
        bool $isOnline,
        null|string $location,
        null|string $locationCountryCode,
        null|string $shortcut,
    ): void {
        $this->name = $name;
        $this->slug = $slug;
        $this->logo = $logo;
        $this->description = $description;
        $this->link = $link;
        $this->isOnline = $isOnline;
        $this->location = $location;
        $this->locationCountryCode = self::normalizeCountryCode($locationCountryCode);
        $this->shortcut = $shortcut;
    }

    private static function normalizeCountryCode(null|string $countryCode): null|string
    {
        return $countryCode !== null ? strtolower($countryCode) : null;
    }
}
