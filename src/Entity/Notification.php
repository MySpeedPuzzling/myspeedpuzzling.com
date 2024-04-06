<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\NotificationType;

#[Entity]
class Notification
{
    #[Column(nullable: true)]
    public null|DateTimeImmutable $readAt = null;

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
        #[Immutable]
        public NotificationType $type,

        #[Column]
        public DateTimeImmutable $notifiedAt,

        #[ManyToOne]
        #[Immutable]
        public null|PuzzleSolvingTime $targetSolvingTime = null,
    ) {
    }
}
