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
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

#[Entity]
class CompetitionRound
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public Competition $competition,
        #[Column]
        public string $name,
        #[Column]
        public int $minutesLimit,
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $startsAt,
        /**
         * @var Collection<int, Puzzle>
         */
        #[ManyToMany(targetEntity: Puzzle::class)]
        public Collection $puzzles = new ArrayCollection(),
        #[Column(nullable: true)]
        public null|string $badgeBackgroundColor = null,
        #[Column(nullable: true)]
        public null|string $badgeTextColor = null,
    ) {
    }
}
