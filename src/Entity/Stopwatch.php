<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToOne;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

#[Entity]
class Stopwatch
{
    public function __construct(
        #[Id]
        #[Column(type: UuidType::NAME, unique: true)]
        readonly public UuidInterface $id,

        #[ManyToOne]
        public null|Player            $player = null,
    ) {
    }
}
