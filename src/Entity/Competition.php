<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

#[Entity]
class Competition
{
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

        #[Column]
        public string $locationCountryCode,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $dateFrom,

        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $dateTo,

        #[ManyToOne]
        public null|Tag $tag,
    ) {
    }
}
