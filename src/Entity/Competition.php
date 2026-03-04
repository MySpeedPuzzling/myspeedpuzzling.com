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
        #[Column(unique: true, nullable: true)]
        public null|string $slug,
        #[Column(unique: true, nullable: true)]
        public null|string $shortcut,
        #[Column(nullable: true)]
        public null|string $logo,
        #[Column(nullable: true)]
        public null|string $description,
        #[Column(nullable: true)]
        public null|string $link,
        #[Column(nullable: true)]
        public null|string $registrationLink,
        #[Column(nullable: true)]
        public null|string $resultsLink,
        #[Column]
        public string $location,
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
        #[Immutable]
        #[ManyToOne]
        public null|Player $addedByPlayer = null,
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $approvedAt = null,
        #[ManyToOne]
        public null|Player $approvedByPlayer = null,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $createdAt = null,
        /**
         * @var Collection<int, Player>
         */
        #[ManyToMany(targetEntity: Player::class)]
        #[JoinTable(name: 'competition_maintainer')]
        public Collection $maintainers = new ArrayCollection(),
    ) {
    }

    public function approve(Player $approvedBy, DateTimeImmutable $approvedAt): void
    {
        $this->approvedAt = $approvedAt;
        $this->approvedByPlayer = $approvedBy;
    }

    public function isApproved(): bool
    {
        return $this->approvedAt !== null;
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
        string $location,
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
}
