<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\HandledOutsideTransaction;

/**
 * Compute the time predictions of every entrant of an analytics studio round in the background
 * (docs/features/competitions-management/analytics-studio.md R28-R33): sent by the `round-predictions` answer when some
 * are not cached yet, at most once a minute per round and visible puzzle set. On the `imports` queue in turns of a few
 * seconds - `turn` counts them, the handler sends the next one itself.
 *
 * No puzzle and no player ids: the handler reads both at its own moment (RoundPuzzlesVisibleNow decides the puzzles),
 * and does nothing once `visibleHash` is no longer the round's visible set or `requestedAtMs` is long past.
 *
 * Handled outside a transaction: it only reads, for seconds, and writes nothing but the studio's cache.
 */
readonly final class WarmAnalyticsStudioPredictions implements HandledOutsideTransaction
{
    public function __construct(
        public string $roundId,
        public string $visibleHash,
        public int $requestedAtMs,
        public int $turn = 0,
    ) {
    }
}
