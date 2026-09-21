<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

/**
 * History of a player's code: one row per change, written by EditPlayerCodeHandler. A log and nothing
 * more for now - no page reads it. A personal code is a member feature that a free trial hands out too,
 * and a code is how players find and add each other, so who held which code when must be answerable.
 */
#[Entity]
#[Index(columns: ['player_id', 'changed_at'])]
#[Index(columns: ['previous_code'])]
class PlayerCodeChange
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Player $player,
        #[Immutable]
        #[Column]
        public string $previousCode,
        #[Immutable]
        #[Column]
        public string $newCode,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $changedAt,
    ) {
    }
}
