<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use Random\Randomizer;

#[Entity]
class Player
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[Immutable]
        #[Column(unique: true)]
        public string $code,

        #[Column(unique: true, nullable: true)]
        public null|string $userId,

        #[Column(nullable: true)]
        public null|string $email,

        #[Column(nullable: true)]
        public null|string $name,

        #[Column(nullable: true)]
        public null|string $country,

        #[Column(nullable: true)]
        public null|string $city,

        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public \DateTimeImmutable $registeredAt,
    ) {
    }

    public function changeProfile(
        null|string $name,
        null|string $email,
        null|string $city,
        null|string $country,
    ): void
    {
        $this->name = $name;
        $this->email = $email;
        $this->city = $city;
        $this->country = $country;
    }
}
