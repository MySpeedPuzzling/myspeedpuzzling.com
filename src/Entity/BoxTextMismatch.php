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
use SpeedPuzzling\Web\Value\BoxTextField;

/**
 * The box says something the catalogue does not (docs/features/puzzle-approvals.md, "Box-text mismatches") - derived
 * from the `box_text` analysis of the puzzle's current picture by BoxTextMismatchDetector, rewritten whole by
 * myspeedpuzzling:detect-box-text-mismatches (daily). At most one per puzzle and field; `valueKey` is what a dismissal
 * remembers (box_text_mismatch_decision), so a dismissed one never comes back while the box reads the same. Never a
 * change proposal - moderators look and fix the record themselves, or dismiss.
 */
#[Entity]
#[Index(columns: ['detected_at'])]
class BoxTextMismatch
{
    /**
     * @param list<string> $boxValues
     * @param list<string> $evidence
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(onDelete: 'CASCADE')]
        public Puzzle $puzzle,
        #[Id]
        #[Immutable]
        #[Column(length: 16, enumType: BoxTextField::class)]
        public BoxTextField $field,
        // The box's values that differ from the catalogue, canonical and sorted, joined with ", "
        #[Immutable]
        #[Column(length: 255)]
        public string $valueKey,
        #[Immutable]
        #[Column(type: Types::JSONB)]
        public array $boxValues,
        // What the catalogue said when it was detected (null = nothing)
        #[Immutable]
        #[Column(length: 255, nullable: true)]
        public null|string $catalogueValue,
        // The printed text it was read from, where the analysis keeps it (piece counts)
        #[Immutable]
        #[Column(type: Types::JSONB)]
        public array $evidence,
        // When the box_text analysis it comes from was stored
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $analysisReceivedAt,
        // When this mismatch (this value) was first detected
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $detectedAt,
    ) {
    }
}
