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
 * The current analysis of one player's photo of the finished puzzle (docs/features/insights/finished-photos.md): the
 * image pipeline found the assembled puzzle in the photo, straightened it and measured it (crop embeddings, piece-scale
 * numbers, glare, how regular the cut is) - a second view of the puzzle's picture for the picture-based difficulty
 * estimate. One row per photo and analyzer, replaced by every newer push, no history. Never shown anywhere.
 *
 * Keyed to the solving time that carries the photo, which the pipeline never learns (it knows a photo only as the
 * puzzle + `photoFingerprint` - no player, no solving time): deleted with the time (cascade), and when the time moves to
 * another puzzle (a merge) the row moves with it and reads as stale (another catalogue picture). Which photo it
 * describes = `photoFingerprint` (PuzzleImageFingerprint of finished_puzzle_photo - a photo replaced or removed reads
 * as stale, and myspeedpuzzling:prune-finished-photo-analyses deletes the row); the puzzle's catalogue picture it was
 * registered against = `imageFingerprint` (the photo is found in it by matching it to that picture, so a new catalogue
 * picture makes the row stale too).
 */
#[Entity]
#[UniqueConstraint(columns: ['analyzer', 'solving_time_id'])]
class FinishedPhotoAnalysis
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
        public PuzzleSolvingTime $solvingTime,
        #[Immutable]
        #[Column(length: 32, enumType: ImageAnalyzer::class)]
        public ImageAnalyzer $analyzer,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: 50)]
        public string $analyzerVersion,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: 64)]
        public string $photoFingerprint,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: 64)]
        public string $imageFingerprint,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::JSONB)]
        public array $result,
        // When the pipeline analysed the photo - its own clock, null when it did not say
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
     * A newer push for the same photo and analyzer - it replaces this one whole.
     *
     * @param array<string, mixed> $result
     */
    public function replace(
        string $analyzerVersion,
        string $photoFingerprint,
        string $imageFingerprint,
        array $result,
        null|DateTimeImmutable $analyzedAt,
        DateTimeImmutable $receivedAt,
    ): void {
        $this->analyzerVersion = $analyzerVersion;
        $this->photoFingerprint = $photoFingerprint;
        $this->imageFingerprint = $imageFingerprint;
        $this->result = $result;
        $this->analyzedAt = $analyzedAt;
        $this->receivedAt = $receivedAt;
    }
}
