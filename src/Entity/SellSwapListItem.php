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
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\ListingType;
use SpeedPuzzling\Web\Value\PuzzleCondition;

#[Entity]
#[UniqueConstraint(columns: ['player_id', 'puzzle_id'])]
class SellSwapListItem
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public Player $player,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public Puzzle $puzzle,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::STRING, enumType: ListingType::class)]
        public ListingType $listingType,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::FLOAT, nullable: true)]
        public null|float $price,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::STRING, enumType: PuzzleCondition::class)]
        public PuzzleCondition $condition,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::TEXT, nullable: true, length: 500)]
        public null|string $comment,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $addedAt,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::BOOLEAN, options: ['default' => true])]
        public bool $publishedOnMarketplace = true,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::BOOLEAN, options: ['default' => false])]
        public bool $reserved = false,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $reservedAt = null,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: UuidType::NAME, nullable: true)]
        public null|UuidInterface $reservedForPlayerId = null,
    ) {
    }

    public function changeListingType(ListingType $listingType): void
    {
        $this->listingType = $listingType;
    }

    public function changePrice(null|float $price): void
    {
        $this->price = $price;
    }

    public function changeCondition(PuzzleCondition $condition): void
    {
        $this->condition = $condition;
    }

    public function changeComment(null|string $comment): void
    {
        $this->comment = $comment;
    }

    public function changePublishedOnMarketplace(bool $publishedOnMarketplace): void
    {
        $this->publishedOnMarketplace = $publishedOnMarketplace;
    }

    public function markAsReserved(null|UuidInterface $reservedForPlayerId = null): void
    {
        $this->reserved = true;
        $this->reservedAt = new DateTimeImmutable();
        $this->reservedForPlayerId = $reservedForPlayerId;
    }

    public function removeReservation(): void
    {
        $this->reserved = false;
        $this->reservedAt = null;
        $this->reservedForPlayerId = null;
    }
}
