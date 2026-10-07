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
use SpeedPuzzling\Web\Value\SuspicionCheckOutcome;

/**
 * The last check of one solo time by the suspicious time scan (docs/features/suspicious-time-review.md, "Checks and
 * versions"): final for its detector version and fingerprint, except no_data while the time is recent.
 *
 * ~450k narrow rows, written only by the scan with batched native upserts
 * (SuspiciousTimeCheckRepository::upsertMany() - a genuine bulk operation); the entity exists for the schema.
 */
#[Entity]
#[Immutable]
class SuspiciousTimeCheck
{
    public function __construct(
        #[Id]
        #[OneToOne]
        #[JoinColumn(name: 'time_id', nullable: false, onDelete: 'CASCADE')]
        public PuzzleSolvingTime $time,
        #[Column(type: Types::SMALLINT)]
        public int $version,
        #[Column(type: Types::STRING, enumType: SuspicionCheckOutcome::class)]
        public SuspicionCheckOutcome $outcome,
        #[Column(length: 32)]
        public string $fingerprint,
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $checkedAt,
    ) {
    }
}
