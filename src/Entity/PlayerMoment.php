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
use SpeedPuzzling\Web\Value\PlayerMomentType;

/**
 * Something that happened to a player: a personal best, a milestone, the first result
 * (docs/features/players-page/README.md). Detected by the community stats cron (PlayerMomentDetector) for results
 * solved in the last 14 days; the id stays stable across runs ((player, dedupe key) is unique), so the future Hub feed
 * can hang likes and comments on it. Moments in the detection window that no longer hold are removed; older ones are
 * history and never touched.
 */
#[Entity]
#[UniqueConstraint(columns: ['player_id', 'dedupe_key'])]
class PlayerMoment
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
        #[Column(type: Types::STRING, length: 30, enumType: PlayerMomentType::class)]
        public PlayerMomentType $type,
        // 'pb:<solving time id>', 'puzzles:<n>', 'pieces:<n>' or 'first'
        #[Immutable]
        #[Column(length: 80)]
        public string $dedupeKey,
        // When the result behind the moment was solved
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $occurredAt,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'CASCADE')]
        public null|PuzzleSolvingTime $solvingTime,
        // Personal best: the piece count it is the best on
        #[Immutable]
        #[Column(type: Types::INTEGER, nullable: true)]
        public null|int $piecesCount,
        // Personal best: the new seconds; milestones: the milestone reached
        #[Immutable]
        #[Column(type: Types::INTEGER, nullable: true)]
        public null|int $value,
        // Personal best: the best it beat
        #[Immutable]
        #[Column(type: Types::INTEGER, nullable: true)]
        public null|int $previousValue,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $detectedAt,
    ) {
    }
}
