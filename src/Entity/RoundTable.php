<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OrderBy;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

#[Entity]
class RoundTable
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[ManyToOne(inversedBy: 'tables')]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public TableRow $row,
        #[Column]
        public int $position,
        #[Column]
        public string $label,
        /**
         * @var Collection<int, TableSpot>
         */
        #[OneToMany(targetEntity: TableSpot::class, mappedBy: 'table')]
        #[OrderBy(['position' => 'ASC'])]
        public Collection $spots = new ArrayCollection(),
    ) {
    }
}
