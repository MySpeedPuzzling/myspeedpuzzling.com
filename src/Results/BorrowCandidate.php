<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * Somebody the asker may ask to borrow one puzzle from (GetBorrowCandidates). Lent out = the owner lent it to somebody
 * right now: shown, never asked for.
 */
final readonly class BorrowCandidate
{
    public function __construct(
        public string $puzzleId,
        public string $ownerId,
        public null|string $ownerName,
        public string $ownerCode,
        public null|string $ownerAvatar,
        public null|string $ownerCountry,
        public bool $lentOut,
    ) {
    }

    public function ownerDisplayName(): string
    {
        return $this->ownerName ?? '#' . strtoupper($this->ownerCode);
    }
}
