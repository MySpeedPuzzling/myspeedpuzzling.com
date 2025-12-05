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
use SpeedPuzzling\Web\Value\ListingType;

#[Entity]
class SoldSwappedItem
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public Player $seller,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public Puzzle $puzzle,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: true)]
        public null|Player $buyerPlayer,
        #[Immutable]
        #[Column(nullable: true)]
        public null|string $buyerName,
        #[Immutable]
        #[Column(type: Types::STRING, enumType: ListingType::class)]
        public ListingType $listingType,
        #[Immutable]
        #[Column(type: Types::FLOAT, nullable: true)]
        public null|float $price,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $soldAt,
    ) {
    }
}
