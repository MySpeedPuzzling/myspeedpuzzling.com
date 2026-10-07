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

/**
 * The player answered "Yes, it's right" to the add/edit form's pace warning (docs/features/suspicious-time-review.md,
 * "Catch it while typing"). The scan still raises such a time, with the reason confirmed_while_saving, so the
 * moderator knows the player was asked.
 */
#[Entity]
#[Immutable]
class SuspiciousTimeConfirmation
{
    public function __construct(
        #[Id]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[ManyToOne]
        #[JoinColumn(name: 'time_id', nullable: false, onDelete: 'CASCADE')]
        public PuzzleSolvingTime $time,
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Player $player,
        // What the form said the player usually takes
        #[Column(type: Types::INTEGER)]
        public int $expectedSeconds,
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $confirmedAt,
    ) {
    }
}
