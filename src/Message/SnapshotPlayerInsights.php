<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\HandledOutsideTransaction;

/**
 * The monthly Insights snapshot of every active player (`myspeedpuzzling:snapshot-player-insights`, docs/features/
 * insights/computation.md "Core sections" §12). `month` = Y-m, null = the current month.
 *
 * Handled outside a transaction: the handler only reads (one report per player, minutes for all of them - held in
 * one transaction it would keep the locks of everything it read, and a deploy's ALTER TABLE would wait behind it);
 * the snapshots are written in chunks by StorePlayerInsightsSnapshots, each its own transaction.
 */
readonly final class SnapshotPlayerInsights implements HandledOutsideTransaction
{
    public function __construct(
        public null|string $month = null,
    ) {
    }
}
