<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

#[Entity]
class Player
{
    public function __construct(
        #[Id]
        #[Column(type: UuidType::NAME, unique: true)]
        readonly public UuidInterface $id,

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
