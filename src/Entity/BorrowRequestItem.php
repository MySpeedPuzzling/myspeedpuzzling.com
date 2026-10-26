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
use SpeedPuzzling\Web\Value\BorrowRequestItemStatus;

/**
 * One puzzle of a borrow request and where it stands (docs/features/wishlist-availability.md). The asker and owner are
 * copied from the request so that "one waiting ask per asker, owner and puzzle" can be a unique index
 * (custom_borrow_item_one_waiting) and the asker's wishlist reads its open asks without the request table
 * (custom_borrow_item_asker_open).
 */
#[Entity]
#[UniqueConstraint(name: 'borrow_request_item_request_puzzle_unique', columns: ['request_id', 'puzzle_id'])]
#[Index(name: 'borrow_request_item_owner_puzzle', columns: ['owner_id', 'puzzle_id'])]
class BorrowRequestItem
{
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, length: 20, enumType: BorrowRequestItemStatus::class)]
    public BorrowRequestItemStatus $status = BorrowRequestItemStatus::Waiting;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $answeredAt = null;

    // Withdrawn, expired or lent
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $closedAt = null;

    #[Immutable]
    #[ManyToOne]
    #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Player $asker;

    #[Immutable]
    #[ManyToOne]
    #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Player $owner;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public BorrowRequest $request,
        // Changes only when the puzzle is merged into another one (puzzleMergedInto())
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Puzzle $puzzle,
    ) {
        $this->asker = $request->asker;
        $this->owner = $request->owner;
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    /**
     * The owner's answer. Only a waiting puzzle is answered - a second answer, or one after the asker withdrew, changes
     * nothing (a resent answer form is harmless). True = it changed.
     */
    public function answer(BorrowRequestItemStatus $answer, DateTimeImmutable $now): bool
    {
        if ($answer->isAnswer() === false) {
            throw new \InvalidArgumentException(sprintf('"%s" is not an answer to a borrow request.', $answer->value));
        }

        if ($this->status !== BorrowRequestItemStatus::Waiting) {
            return false;
        }

        $this->status = $answer;
        $this->answeredAt = $now;

        return true;
    }

    public function withdraw(DateTimeImmutable $now): bool
    {
        return $this->close(BorrowRequestItemStatus::Withdrawn, $now);
    }

    public function expire(DateTimeImmutable $now): bool
    {
        return $this->close(BorrowRequestItemStatus::Expired, $now);
    }

    /**
     * The puzzle changed hands (LendPuzzleToPlayer / BorrowPuzzleFromPlayer) - also while it was still waiting: the
     * two agreed some other way.
     */
    public function markLent(DateTimeImmutable $now): bool
    {
        return $this->close(BorrowRequestItemStatus::Lent, $now);
    }

    /**
     * The puzzle was merged into the survivor of a puzzle merge (ApprovePuzzleMergeRequestHandler).
     */
    public function puzzleMergedInto(Puzzle $survivor): void
    {
        $this->puzzle = $survivor;
    }

    private function close(BorrowRequestItemStatus $status, DateTimeImmutable $now): bool
    {
        if ($this->isOpen() === false) {
            return false;
        }

        $this->status = $status;
        $this->closedAt = $now;

        return true;
    }
}
