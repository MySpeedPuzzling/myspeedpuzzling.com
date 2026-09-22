<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Multiscan batch: every puzzle is handed to the same person.
 */
readonly final class LendPuzzlesToPlayer
{
    /**
     * @param list<string> $puzzleIds
     */
    public function __construct(
        public string $ownerPlayerId,
        public array $puzzleIds,
        public null|string $borrowerPlayerId = null,
        public null|string $borrowerName = null,
        public null|string $notes = null,
    ) {
    }
}
