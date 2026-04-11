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
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

#[Entity]
class CompetitionSeries
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
        $this->locationCountryCode = $locationCountryCode;
        $this->shortcut = $shortcut;
    }
}
