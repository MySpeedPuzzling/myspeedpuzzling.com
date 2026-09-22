<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Multiscan batch: every puzzle goes into the same collection (null = system collection).
 */
readonly final class AddPuzzlesToCollection
{
    /**
     * @param list<string> $puzzleIds
     */
    public function __construct(
        public string $playerId,
        public array $puzzleIds,
        public null|string $collectionId,
        public null|string $comment = null,
    ) {
    }
}
