<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Multiscan batch: closes the open lend of every puzzle the acting player
 * either owns (puzzles came back) or holds (giving them back). Puzzles may
 * belong to different counterparties.
 */
readonly final class ReturnLentPuzzles
{
    /**
     * @param list<string> $puzzleIds
     */
    public function __construct(
        public string $actingPlayerId,
        public array $puzzleIds,
    ) {
    }
}
