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
use SpeedPuzzling\Web\Value\DuplicateCaseStatus;
use SpeedPuzzling\Web\Value\DuplicateDetectedBy;
use SpeedPuzzling\Web\Value\DuplicateKind;
use SpeedPuzzling\Web\Value\DuplicateResolvedVia;
use SpeedPuzzling\Web\Value\DuplicateTier;

/**
 * Two results of one person that look like the same result saved twice (docs/features/duplicate-results.md).
 *
 * One row per person per pair: a teammate copy is a case for each registered member, and each decides for
 * themselves. The result ids deliberately have no foreign keys (like puzzle_moderation_decision) - the row
 * outlives the deletion it records, and the snapshot keeps what the results looked like when detected.
 */
#[Entity]
#[UniqueConstraint(columns: ['player_id', 'time_a_id', 'time_b_id'])]
#[Index(columns: ['player_id', 'status'])]
class ResultDuplicateCase
{
    // Changed only through the named methods below
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, enumType: DuplicateCaseStatus::class)]
    public DuplicateCaseStatus $status = DuplicateCaseStatus::Open;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $resolvedAt = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, nullable: true, enumType: DuplicateResolvedVia::class)]
    public null|DuplicateResolvedVia $resolvedVia = null;

    /**
     * @param array<string, mixed> $snapshot see DuplicateCandidate::snapshot()
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        // The person the case is about - not necessarily the tracker of either copy
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Player $player,
        // The older copy (saved first)
        #[Immutable]
        #[Column(name: 'time_a_id', type: UuidType::NAME)]
        public UuidInterface $timeAId,
        #[Immutable]
        #[Column(name: 'time_b_id', type: UuidType::NAME)]
        public UuidInterface $timeBId,
        #[Immutable]
        #[Column(type: Types::STRING, enumType: DuplicateTier::class)]
        public DuplicateTier $tier,
        #[Immutable]
        #[Column(type: Types::STRING, enumType: DuplicateKind::class)]
        public DuplicateKind $kind,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $detectedAt,
        #[Immutable]
        #[Column(type: Types::STRING, enumType: DuplicateDetectedBy::class)]
        public DuplicateDetectedBy $detectedBy,
        #[Immutable]
        #[Column(type: Types::JSON)]
        public array $snapshot,
    ) {
    }

    public static function key(string $playerId, string $timeAId, string $timeBId): string
    {
        return $playerId . '|' . $timeAId . '|' . $timeBId;
    }

    public function pairKey(): string
    {
        return self::key($this->player->id->toString(), $this->timeAId->toString(), $this->timeBId->toString());
    }

    public function involves(string $timeId): bool
    {
        return $this->timeAId->toString() === strtolower($timeId) || $this->timeBId->toString() === strtolower($timeId);
    }

    public function otherTimeId(string $timeId): string
    {
        return $this->timeAId->toString() === strtolower($timeId) ? $this->timeBId->toString() : $this->timeAId->toString();
    }

    public function isOpen(): bool
    {
        return $this->status === DuplicateCaseStatus::Open;
    }

    /**
     * Somebody kept one copy and deleted the other - closes the case of everybody it was about.
     */
    public function copyDeleted(DateTimeImmutable $now, DuplicateResolvedVia $via): void
    {
        $this->decide(DuplicateCaseStatus::CopyDeleted, $now, $via);
    }

    /**
     * The person says these are two different solves - for them only, others in a group decide for themselves.
     */
    public function confirmBothReal(DateTimeImmutable $now, DuplicateResolvedVia $via): void
    {
        $this->decide(DuplicateCaseStatus::BothReal, $now, $via);
    }

    public function autoRemoved(DateTimeImmutable $now): void
    {
        $this->decide(DuplicateCaseStatus::AutoRemoved, $now, DuplicateResolvedVia::Automatic);
    }

    /**
     * The tracker brought the removed copy back - it was another solve after all.
     */
    public function removalUndone(DateTimeImmutable $now): void
    {
        if ($this->status !== DuplicateCaseStatus::AutoRemoved) {
            return;
        }

        $this->status = DuplicateCaseStatus::Undone;
        $this->resolvedAt = $now;
    }

    /**
     * The pair no longer matches - a copy was deleted, its time or date changed, or it was merged away.
     * Only an open case can go; a decided one keeps its decision.
     */
    public function markGone(DateTimeImmutable $now): void
    {
        if ($this->status !== DuplicateCaseStatus::Open) {
            return;
        }

        $this->status = DuplicateCaseStatus::Gone;
        $this->resolvedAt = $now;
    }

    private function decide(DuplicateCaseStatus $status, DateTimeImmutable $now, DuplicateResolvedVia $via): void
    {
        if ($this->status !== DuplicateCaseStatus::Open) {
            return;
        }

        $this->status = $status;
        $this->resolvedAt = $now;
        $this->resolvedVia = $via;
    }
}
