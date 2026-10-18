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
use SpeedPuzzling\Web\Value\PuzzleTagCorrectionVerdict;

/**
 * An admin's or a moderator's word on one tag of one puzzle (docs/features/insights/tags.md, "Corrections"): its own
 * data, never an edit of an analysis, so it survives every re-run - the tag writer applies it on top of whatever the
 * `tags` analysis says. One per puzzle and key; a new word on the same key replaces it, removing it gives the key back
 * to the analysis. Deleted with the puzzle (a merge: the survivor keeps its own).
 */
#[Entity]
#[UniqueConstraint(columns: ['puzzle_id', 'tag_key'])]
class PuzzleTagCorrection
{
    public const int NOTE_MAX_LENGTH = 500;

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
        #[Column(length: 100)]
        public string $tagKey,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: 16, enumType: PuzzleTagCorrectionVerdict::class)]
        public PuzzleTagCorrectionVerdict $verdict,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::TEXT, nullable: true)]
        public null|string $note,
        // Null once that player's account is deleted - the correction stays, it is about the puzzle
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public null|Player $decidedBy,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $decidedAt,
        // The taxonomy version the key was meant in (a later version may map a deprecated key to its replacement)
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: 16)]
        public string $taxonomyVersion,
    ) {
    }

    public function change(
        PuzzleTagCorrectionVerdict $verdict,
        null|string $note,
        Player $decidedBy,
        DateTimeImmutable $decidedAt,
        string $taxonomyVersion,
    ): void {
        $this->verdict = $verdict;
        $this->note = $note;
        $this->decidedBy = $decidedBy;
        $this->decidedAt = $decidedAt;
        $this->taxonomyVersion = $taxonomyVersion;
    }
}
