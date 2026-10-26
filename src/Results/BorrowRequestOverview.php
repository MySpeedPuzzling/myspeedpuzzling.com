<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\BorrowRequestItemStatus;

/**
 * A borrow request as one of its two sides sees it (GetBorrowRequests): the other person (asker for the owner, owner
 * for the asker) and the puzzles with their status. A private counterpart is named - asking was their consent, and an
 * owner was only asked while the asker could see them - but never shown by their picture or linked.
 */
final readonly class BorrowRequestOverview
{
    /**
     * @param list<BorrowRequestItemOverview> $items
     */
    public function __construct(
        public string $requestId,
        public string $askerId,
        public string $ownerId,
        public string $counterpartId,
        public null|string $counterpartName,
        public string $counterpartCode,
        public null|string $counterpartAvatar,
        public bool $counterpartIsPrivate,
        public null|string $note,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
        public bool $replied,
        public array $items,
    ) {
    }

    public function counterpartDisplayName(): string
    {
        return $this->counterpartName ?? '#' . strtoupper($this->counterpartCode);
    }

    public function countWithStatus(BorrowRequestItemStatus ...$statuses): int
    {
        return count(array_filter($this->items, static fn (BorrowRequestItemOverview $item): bool => in_array($item->status, $statuses, true)));
    }

    public function isWaiting(): bool
    {
        return $this->countWithStatus(BorrowRequestItemStatus::Waiting) > 0;
    }

    public function isOpen(): bool
    {
        return $this->countWithStatus(BorrowRequestItemStatus::Waiting, BorrowRequestItemStatus::Yes) > 0;
    }
}
