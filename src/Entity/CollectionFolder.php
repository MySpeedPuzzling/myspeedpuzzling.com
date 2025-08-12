<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

#[Entity]
class CollectionFolder
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        #[Immutable]
        public Player $player,

        #[Column]
        public string $name,

        #[Column(type: Types::BOOLEAN)]
        public bool $isSystem = false,

        #[Column(nullable: true)]
        public null|string $color = null,

        #[Column(nullable: true)]
        public null|string $description = null,

        #[Column(nullable: true)]
        public null|string $systemKey = null,
    ) {
    }

    public function changeName(string $name): void
    {
        if ($this->isSystem) {
            throw new \DomainException('Cannot modify system folder');
        }

        $this->name = $name;
    }

    public function changeColor(null|string $color): void
    {
        if ($this->isSystem) {
            throw new \DomainException('Cannot modify system folder');
        }

        $this->color = $color;
    }

    public function changeDescription(null|string $description): void
    {
        if ($this->isSystem) {
            throw new \DomainException('Cannot modify system folder');
        }

        $this->description = $description;
    }
}