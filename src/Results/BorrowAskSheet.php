<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\BorrowAskSkipReason;

/**
 * The ask-to-borrow sheet (BorrowAskSheetBuilder, docs/features/wishlist-availability.md): one group per person who
 * owns any of the picked puzzles - filled by the suggested plan, the others empty until a puzzle is moved there - and the
 * picked puzzles nobody can be asked for, with why.
 */
final readonly class BorrowAskSheet
{
    /**
     * @param array<string, BorrowCandidate> $owners owner id => who (any of their candidates)
     * @param array<string, list<string>> $puzzlesByOwner owner id => puzzle ids, plan order first, every owner present
     * @param array<string, list<string>> $ownersByPuzzle puzzle id => owner ids who could lend it
     * @param array<string, WishListItemOverview> $puzzles puzzle id => what is shown of it
     * @param array<string, BorrowAskSkipReason> $notAskable puzzle id => why it cannot be asked for
     */
    public function __construct(
        public array $owners,
        public array $puzzlesByOwner,
        public array $ownersByPuzzle,
        public array $puzzles,
        public array $notAskable,
        public bool $truncated,
    ) {
    }

    public function askedPuzzleCount(): int
    {
        return array_sum(array_map(count(...), $this->puzzlesByOwner));
    }

    public function askedPeopleCount(): int
    {
        return count(array_filter($this->puzzlesByOwner, static fn (array $puzzleIds): bool => $puzzleIds !== []));
    }
}
