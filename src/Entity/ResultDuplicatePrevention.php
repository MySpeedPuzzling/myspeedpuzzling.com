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
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\DuplicatePreventionKind;
use SpeedPuzzling\Web\Value\SolvingTimeSource;

/**
 * Append-only log of every save that did not become a second copy of a result (docs/features/duplicate-results.md).
 * The result and puzzle ids are plain columns without FKs, like puzzle_moderation_decision - the row outlives
 * the result it is about.
 */
#[Entity]
#[Index(columns: ['created_at'])]
class ResultDuplicatePrevention
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Player $player,
        #[Immutable]
        #[Column(type: Types::STRING, enumType: DuplicatePreventionKind::class)]
        public DuplicatePreventionKind $kind,
        // The result already saved (the one a resend was answered with)
        #[Immutable]
        #[Column(type: UuidType::NAME)]
        public UuidInterface $timeId,
        #[Immutable]
        #[Column(type: UuidType::NAME)]
        public UuidInterface $puzzleId,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
        #[Immutable]
        #[Column(type: Types::STRING, enumType: SolvingTimeSource::class)]
        public SolvingTimeSource $via,
    ) {
    }
}
