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
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

/**
 * A player's pace (direct baseline) at one piece count from one qualifying first attempt on: one row
 * per qualifying first attempt from the 5th on, holding the baseline right after it. Written only by
 * PlayerPaceHistoryWriter (15-min batch + backfill, set-based); read by the as-of difficulty indices
 * through PaceTimeline. See docs/features/puzzle-intelligence/README.md "Pace history".
 */
#[Entity]
#[UniqueConstraint(columns: ['player_id', 'pieces_count', 'qualifying_count'])]
#[Index(columns: ['player_id', 'pieces_count', 'valid_from', 'valid_from_tracked_at'])]
class PlayerPaceHistory
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
        #[Column]
        public int $piecesCount,
        // The solve that moved the pace: COALESCE(finished_at, tracked_at) ...
        #[Immutable]
        #[Column]
        public DateTimeImmutable $validFrom,
        // ... and its tracked_at - the tie-break of same-day solves (finished_at is a date)
        #[Immutable]
        #[Column]
        public DateTimeImmutable $validFromTrackedAt,
        // A deleted time takes its row with it; the next batch rewrites the pair from there
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public PuzzleSolvingTime $time,
        #[Immutable]
        #[Column]
        public int $baselineSeconds,
        // Position of the solve among the pair's qualifying first attempts (5 = the first row)
        #[Immutable]
        #[Column]
        public int $qualifyingCount,
        // ln(baseline / the previous row's baseline); null on the pair's first row
        #[Immutable]
        #[Column(nullable: true)]
        public null|float $step,
        // Improvement drift (log) as of this row - defined in the README, filled by the prediction model v2
        #[Immutable]
        #[Column(nullable: true)]
        public null|float $drift,
        // Sum of the hashes of the pair's qualifying solves up to this one: what the writer compares to find changes
        #[Immutable]
        #[Column(type: Types::BIGINT)]
        public int $inputsChecksum,
    ) {
    }
}
