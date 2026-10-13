<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\OnlineMediaStore;

/**
 * One page of the orphan sweep of a store (docs/features/online-competitions/README.md §8): evidence objects whose row
 * is gone - a deleted competition, round or participant takes the rows with it (cascade), never the objects - and
 * that are older than a day are deleted. Part of the daily prune (myspeedpuzzling:prune-online-media).
 */
readonly final class SweepOnlineMediaOrphans
{
    public function __construct(
        public OnlineMediaStore $store,
    ) {
    }
}
