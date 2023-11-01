<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

#[Entity]
class Manufacturer
{
    public function __construct(
        #[Id]
        #[Column(type: UuidType::NAME, unique: true)]
        readonly public UuidInterface $id,

        #[Column]
        public string $name,
    ) {
    }
}
