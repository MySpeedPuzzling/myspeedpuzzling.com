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
use SpeedPuzzling\Web\Value\PuzzleTagSource;

/**
 * A tag of a puzzle's picture (docs/features/insights/tags.md) - derived, never edited: the `tags` analysis of the
 * puzzle's current picture ± the corrections of admins and moderators (puzzle_tag_correction), written set-based by
 * PuzzleTagWriter (myspeedpuzzling:refresh-puzzle-tags, daily after the image pipeline's derive step). A secret puzzle
 * has none; readers still check hide_until / hide_image_until, the rows of a puzzle that became secret go with the
 * next run. Stored, not shown publicly: Insights and the estimates read it.
 */
#[Entity]
#[Index(columns: ['tag_key'])]
class PuzzleTag
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(onDelete: 'CASCADE')]
        public Puzzle $puzzle,
        // `<facet>.<key>` (PuzzleTaxonomy) - every key the analysis assigned, known to the registry or not
        #[Id]
        #[Immutable]
        #[Column(length: 100)]
        public string $tagKey,
        // The analysis' confidence 0..1 (for traits the corpus percentile); null for a tag only a correction gives
        #[Immutable]
        #[Column(type: Types::FLOAT, nullable: true)]
        public null|float $score,
        // The analysis assigned it although its sources disagree (a style or a scene)
        #[Immutable]
        #[Column(options: ['default' => false])]
        public bool $uncertain,
        #[Immutable]
        #[Column(length: 16, enumType: PuzzleTagSource::class)]
        public PuzzleTagSource $source,
        // The `tags` analysis it comes from - null for a tag only a correction gives
        #[Immutable]
        #[Column(length: 50, nullable: true)]
        public null|string $analysisVersion,
        #[Immutable]
        #[Column(length: 16, nullable: true)]
        public null|string $taxonomyVersion,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
