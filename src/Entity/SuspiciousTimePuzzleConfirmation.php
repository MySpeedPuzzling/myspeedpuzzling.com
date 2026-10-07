<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

/**
 * "The piece count is right" on a puzzle card of the moderator queue (docs/features/suspicious-time-review.md,
 * "The piece count is wrong"): the puzzle's cases go on one by one. Bound to the piece count it was given for - it
 * lapses when the puzzle's count changes.
 */
#[Entity]
class SuspiciousTimePuzzleConfirmation
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[OneToOne]
        #[JoinColumn(name: 'puzzle_id', nullable: false, onDelete: 'CASCADE')]
        public Puzzle $puzzle,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::INTEGER)]
        public int $piecesCount,
        // The moderator (a player id, no FK - the decision log has the name)
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: UuidType::NAME, nullable: true)]
        public null|UuidInterface $confirmedById,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $confirmedAt,
    ) {
    }

    public function appliesTo(int $piecesCount): bool
    {
        return $this->piecesCount === $piecesCount;
    }

    public function confirmAgain(int $piecesCount, null|UuidInterface $confirmedById, DateTimeImmutable $now): void
    {
        $this->piecesCount = $piecesCount;
        $this->confirmedById = $confirmedById;
        $this->confirmedAt = $now;
    }
}
