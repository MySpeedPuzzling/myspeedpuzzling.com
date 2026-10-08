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
 * A moderator's word about a puzzle in time verification (docs/features/suspicious-time-review.md, "A hard puzzle"):
 * its piece count is right and its times are too slow only from slowThreshold × what was expected - a puzzle all of
 * one colour takes everybody many times longer than its piece count suggests. Bound to the piece count it was given
 * for: it lapses when the puzzle's count changes. Rows without a threshold are earlier "the piece count is right"
 * confirmations - they no longer change anything.
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
        // When it was last set - the scan judges again every time of the puzzle checked before (GetSuspiciousTimeCandidates)
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $confirmedAt,
        // A slow time on the puzzle is raised only from this many times its expectation (SuspiciousTimeClassifier)
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::FLOAT, nullable: true)]
        public null|float $slowThreshold = null,
    ) {
    }

    public function appliesTo(int $piecesCount): bool
    {
        return $this->piecesCount === $piecesCount;
    }

    /**
     * A new threshold, or none (null) - for the puzzle's current piece count.
     */
    public function changeSlowThreshold(int $piecesCount, null|float $slowThreshold, null|UuidInterface $confirmedById, DateTimeImmutable $now): void
    {
        $this->piecesCount = $piecesCount;
        $this->slowThreshold = $slowThreshold;
        $this->confirmedById = $confirmedById;
        $this->confirmedAt = $now;
    }
}
