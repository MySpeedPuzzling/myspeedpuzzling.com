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
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\BoxTextField;

/**
 * "Dismiss" on a box-text mismatch (docs/features/puzzle-approvals.md, "Box-text mismatches"): remembered per puzzle,
 * field and value, so the mismatch never returns while the box reads the same - a value read later is a new mismatch.
 * Deleted with the puzzle.
 */
#[Entity]
#[UniqueConstraint(columns: ['puzzle_id', 'field', 'value_key'])]
class BoxTextMismatchDecision
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Puzzle $puzzle,
        #[Immutable]
        #[Column(length: 16, enumType: BoxTextField::class)]
        public BoxTextField $field,
        #[Immutable]
        #[Column(length: 255)]
        public string $valueKey,
        // Null once that player's account is deleted
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public null|Player $decidedBy,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $decidedAt,
    ) {
    }
}
