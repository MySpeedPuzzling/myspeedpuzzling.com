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
use SpeedPuzzling\Web\Value\ImageAnalyzer;

/**
 * The current analysis of a puzzle's box image by one analyzer (docs/features/internal-api.md, "Puzzle image
 * analysis"): made outside the app by a pipeline on local models and pushed through the internal API - one row per
 * puzzle and analyzer, replaced by every newer push, no history.
 *
 * `result` is the analyzer's payload as it came (a JSON object, checked only for its size and, for `artwork`, its
 * box/corners - PuzzleImageAnalysisInput); `analyzerVersion` says how it was made, `imageFingerprint` which picture it
 * describes (PuzzleImageFingerprint - a row whose fingerprint is not the puzzle's current one describes an older
 * picture). Deleted with the puzzle - a merge deletes the merged puzzle's rows, the survivor keeps its own.
 */
#[Entity]
#[UniqueConstraint(columns: ['puzzle_id', 'analyzer'])]
class PuzzleImageAnalysis
{
    /**
     * @param array<string, mixed> $result
     */
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
        #[Column(length: 32, enumType: ImageAnalyzer::class)]
        public ImageAnalyzer $analyzer,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: 50)]
        public string $analyzerVersion,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: 64)]
        public string $imageFingerprint,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::JSONB)]
        public array $result,
        // When the pipeline analysed the image - its own clock, null when it did not say
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $analyzedAt,
        // When the app stored it
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $receivedAt,
    ) {
    }

    /**
     * A newer push for the same puzzle and analyzer - it replaces this one whole.
     *
     * @param array<string, mixed> $result
     */
    public function replace(
        string $analyzerVersion,
        string $imageFingerprint,
        array $result,
        null|DateTimeImmutable $analyzedAt,
        DateTimeImmutable $receivedAt,
    ): void {
        $this->analyzerVersion = $analyzerVersion;
        $this->imageFingerprint = $imageFingerprint;
        $this->result = $result;
        $this->analyzedAt = $analyzedAt;
        $this->receivedAt = $receivedAt;
    }
}
