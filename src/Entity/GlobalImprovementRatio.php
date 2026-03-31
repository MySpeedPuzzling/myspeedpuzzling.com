<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

#[Entity]
#[UniqueConstraint(columns: ['pieces_count', 'from_attempt', 'gap_bucket'])]
class GlobalImprovementRatio
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Column]
        public int $piecesCount,
        #[Column]
        public int $fromAttempt,
        #[Column(length: 10)]
        public string $gapBucket,
        #[Column]
        public float $medianRatio,
        #[Column]
        public int $sampleSize,
        #[Column]
        public DateTimeImmutable $computedAt = new DateTimeImmutable(),
    ) {
    }
}
