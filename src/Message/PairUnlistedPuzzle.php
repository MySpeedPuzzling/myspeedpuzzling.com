<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;

/**
 * "It is this catalogue puzzle" (docs/features/data-import/unlisted-puzzles.md "Pairing"): the player says which
 * catalogue puzzle one of their unlisted puzzles is. The handler checks, records the pairing and hands it to the
 * `imports` worker (ProcessUnlistedPairing), which turns the unlisted results into real ones through the import's
 * writer - it answers with an UnlistedPairingOutcome and adds nothing itself.
 *
 * `$requestId` is chosen by the page for one press of the button: sent again it answers the same pairing.
 *
 * Serialized with the player's "Add"s: one of them at a time.
 */
readonly final class PairUnlistedPuzzle implements SerializedByLock
{
    public function __construct(
        public string $requestId,
        public string $unlistedPuzzleId,
        public string $playerId,
        public string $puzzleId,
    ) {
    }

    public function lockKey(): string
    {
        return 'data-import-add-' . strtolower($this->playerId);
    }
}
