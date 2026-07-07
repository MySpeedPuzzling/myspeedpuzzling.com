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

#[Entity]
#[Table]
#[UniqueConstraint(columns: ['series_id', 'slug'])]
class Competition
{
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
        $this->locationCountryCode = $locationCountryCode;
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo;
        $this->isOnline = $isOnline;
    }

    public function updateRegistrationSettings(
        bool $registrationManaged,
        null|int $capacity,
        null|DateTimeImmutable $registrationOpensAt,
        null|DateTimeImmutable $registrationClosesAt,
        null|string $entryFeeText,
        null|string $paymentInstructions,
    ): void {
        $this->registrationManaged = $registrationManaged;
        $this->capacity = $capacity;
        $this->registrationOpensAt = $registrationOpensAt;
        $this->registrationClosesAt = $registrationClosesAt;
        $this->entryFeeText = $entryFeeText;
        $this->paymentInstructions = $paymentInstructions;

        if ($registrationManaged === true) {
            // One source of truth for how to register
            $this->registrationLink = null;
        }
    }

    public function isRegistrationOpen(DateTimeImmutable $now): bool
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
}
