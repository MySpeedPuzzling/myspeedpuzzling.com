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
use SpeedPuzzling\Web\Value\Unlisted\UnlistedPairingState;

/**
 * One "It is this catalogue puzzle" of a player about one of their unlisted puzzles
 * (docs/features/data-import/unlisted-puzzles.md "Pairing") - kept for good: who, when, which catalogue puzzle, what
 * came of it, and whether it was taken back. What it made is on the results and marks themselves (their
 * `pairing_id`), so an Undo is exact.
 *
 * `puzzleId` is a plain id: the record of a decision, not a link the catalogue has to keep.
 */
#[Entity]
#[UniqueConstraint(columns: ['request_id'])]
#[Index(columns: ['unlisted_puzzle_id', 'started_at'])]
#[Index(columns: ['state', 'last_activity_at'])]
class UnlistedPairing
{
    /** How long the player may take a pairing back */
    public const int UNDO_DAYS = 30;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: 16, enumType: UnlistedPairingState::class)]
    public UnlistedPairingState $state = UnlistedPairingState::Running;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE)]
    public DateTimeImmutable $lastActivityAt;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $finishedAt = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $undoRequestedAt = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $undoneAt = null;

    /** Results the pairing made on the catalogue puzzle */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $resultsAdded = 0;

    /** Results the player had already - nothing was added for them */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $resultsAlreadyYours = 0;

    /** Results that stayed unlisted, each with its reason */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $resultsKept = 0;

    /** Collection and wish list items the pairing created */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $itemsAdded = 0;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $itemsAlreadyYours = 0;

    /** An Undo: results it deleted, and results it left because they were changed since */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $resultsTakenBack = 0;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(options: ['default' => 0])]
    public int $resultsKeptByUndo = 0;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        /** The page's own id of the press: the same request answers the same pairing */
        #[Immutable]
        #[Column(type: UuidType::NAME)]
        public UuidInterface $requestId,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Player $player,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public UnlistedPuzzle $unlistedPuzzle,
        #[Immutable]
        #[Column(type: UuidType::NAME)]
        public UuidInterface $puzzleId,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $startedAt,
    ) {
        $this->lastActivityAt = $startedAt;
    }

    public function isWorking(): bool
    {
        return $this->state === UnlistedPairingState::Running || $this->state === UnlistedPairingState::Undoing;
    }

    public function workedOn(DateTimeImmutable $now): void
    {
        $this->lastActivityAt = $now;
    }

    /**
     * @param array{resultsAdded: int, resultsAlreadyYours: int, resultsKept: int, itemsAdded: int, itemsAlreadyYours: int} $counts
     */
    public function finished(array $counts, DateTimeImmutable $now): void
    {
        $this->state = UnlistedPairingState::Finished;
        $this->finishedAt = $now;
        $this->lastActivityAt = $now;
        $this->resultsAdded = $counts['resultsAdded'];
        $this->resultsAlreadyYours = $counts['resultsAlreadyYours'];
        $this->resultsKept = $counts['resultsKept'];
        $this->itemsAdded = $counts['itemsAdded'];
        $this->itemsAlreadyYours = $counts['itemsAlreadyYours'];
    }

    public function canBeUndone(DateTimeImmutable $now): bool
    {
        return $this->state === UnlistedPairingState::Finished
            && $this->finishedAt !== null
            && $this->finishedAt >= $now->modify(sprintf('-%d days', self::UNDO_DAYS));
    }

    public function undoRequested(DateTimeImmutable $now): void
    {
        $this->state = UnlistedPairingState::Undoing;
        $this->undoRequestedAt = $now;
        $this->lastActivityAt = $now;
    }

    public function resultTakenBack(bool $deleted, DateTimeImmutable $now): void
    {
        $this->lastActivityAt = $now;
        $this->resultsTakenBack += $deleted ? 1 : 0;
        $this->resultsKeptByUndo += $deleted ? 0 : 1;
    }

    public function undone(DateTimeImmutable $now): void
    {
        $this->state = UnlistedPairingState::Undone;
        $this->undoneAt = $now;
        $this->lastActivityAt = $now;
    }
}
