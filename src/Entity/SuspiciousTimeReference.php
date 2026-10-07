<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use JetBrains\PhpStorm\Immutable;
use SpeedPuzzling\Web\Value\PaceReference;
use SpeedPuzzling\Web\Value\PuzzlingType;
use SpeedPuzzling\Web\Value\SuspicionPiecesRange;

/**
 * The community's pace for one piece-count range and puzzling type - only where there are enough non-suspicious
 * results (SuspiciousTimeClassifier::REFERENCE_MIN_SAMPLE; a missing row = no reference). Refreshed by every scan; a
 * row whose sample falls below the minimum keeps its last values. Read by the pace fallback, the bars for players
 * without times of their own and the slow floor (docs/features/suspicious-time-review.md, "The expected time").
 */
#[Entity]
class SuspiciousTimeReference
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(length: 16, enumType: SuspicionPiecesRange::class)]
        public SuspicionPiecesRange $piecesRange,
        #[Id]
        #[Immutable]
        #[Column(length: 16, enumType: PuzzlingType::class)]
        public PuzzlingType $puzzlingType,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::FLOAT)]
        public float $medianPpm,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::FLOAT)]
        public float $p999Ppm,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::INTEGER)]
        public int $sampleSize,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $computedAt,
    ) {
    }

    public static function of(PaceReference $reference, DateTimeImmutable $now): self
    {
        return new self($reference->piecesRange, $reference->puzzlingType, $reference->medianPpm, $reference->p999Ppm, $reference->sampleSize, $now);
    }

    public function refresh(PaceReference $reference, DateTimeImmutable $now): void
    {
        $this->medianPpm = $reference->medianPpm;
        $this->p999Ppm = $reference->p999Ppm;
        $this->sampleSize = $reference->sampleSize;
        $this->computedAt = $now;
    }

    public function toPaceReference(): PaceReference
    {
        return new PaceReference($this->piecesRange, $this->puzzlingType, $this->medianPpm, $this->p999Ppm, $this->sampleSize);
    }
}
