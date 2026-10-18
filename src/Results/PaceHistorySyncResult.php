<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What one PlayerPaceHistoryWriter::sync() did: pairs (player × piece count) looked at, pairs whose
 * rows changed, rows deleted and inserted.
 */
final class PaceHistorySyncResult
{
    public function __construct(
        public int $pairs = 0,
        public int $changedPairs = 0,
        public int $deleted = 0,
        public int $inserted = 0,
    ) {
    }

    public function add(self $other): void
    {
        $this->pairs += $other->pairs;
        $this->changedPairs += $other->changedPairs;
        $this->deleted += $other->deleted;
        $this->inserted += $other->inserted;
    }
}
