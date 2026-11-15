<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;

/**
 * Undo of one "It is this catalogue puzzle" (docs/features/data-import/unlisted-puzzles.md "Undo of a pairing"),
 * by the player, within UnlistedPairing::UNDO_DAYS: what the pairing made is taken back by the `imports` worker and
 * the puzzle is unlisted again. A result changed since is kept and counted.
 */
readonly final class UndoUnlistedPairing implements SerializedByLock
{
    public function __construct(
        public string $pairingId,
        public string $playerId,
    ) {
    }

    public function lockKey(): string
    {
        return 'data-import-add-' . strtolower($this->playerId);
    }
}
