<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Multiscan batch: every puzzle comes from the same person.
 */
readonly final class BorrowPuzzlesFromPlayer
{
    /**
     * @param list<string> $puzzleIds
     */
    public function __construct(
        public string $borrowerPlayerId,
        public array $puzzleIds,
        public null|string $ownerPlayerId = null,
        public null|string $ownerName = null,
        public null|string $notes = null,
    ) {
    }
}
