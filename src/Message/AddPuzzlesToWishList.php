<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Multiscan batch.
 */
readonly final class AddPuzzlesToWishList
{
    /**
     * @param list<string> $puzzleIds
     */
    public function __construct(
        public string $playerId,
        public array $puzzleIds,
    ) {
    }
}
