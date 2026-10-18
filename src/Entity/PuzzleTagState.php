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

/**
 * What a puzzle's tags (puzzle_tag) were last written from (docs/features/insights/tags.md) - the per-puzzle watermark
 * of PuzzleTagWriter: a run rewrites only the puzzles whose current input differs from what is stored here. One row
 * per puzzle whose tags were written from something (an analysis, corrections - or both); none for a puzzle with
 * neither, nor for a secret one. Written set-based by the writer only.
 */
#[Entity]
class PuzzleTagState
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[OneToOne]
        #[JoinColumn(onDelete: 'CASCADE')]
        public Puzzle $puzzle,
        // md5 of the analysis' version, taxonomy and assigned tags - null when no current analysis counted
        #[Immutable]
        #[Column(length: 32, nullable: true)]
        public null|string $analysisSignature,
        // md5 of the puzzle's corrections (key + verdict) - null without corrections
        #[Immutable]
        #[Column(length: 32, nullable: true)]
        public null|string $correctionsSignature,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $writtenAt,
    ) {
    }
}
