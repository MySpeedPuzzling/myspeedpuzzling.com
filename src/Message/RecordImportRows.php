<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\RequiresFreshEntityManagerState;

/**
 * A batch of a sheet's rows as the reader handed them over (at most DataImportLimits::ROWS_PER_BATCH), written in
 * one short transaction together with how far reading is. Answers whether reading goes on - false when the import
 * is not being read by this read any more (discarded meanwhile, given up as stalled, started again).
 *
 * A fresh entity manager for every batch: a file is up to a hundred of them, and none may stay in memory.
 */
readonly final class RecordImportRows implements RequiresFreshEntityManagerState
{
    /**
     * @param list<array{number: int, cells: array<int, array<string, mixed>>}> $rows cells as ImportRowCells makes them
     * @param int $lastColumn the right-most column a value of these rows stands in
     * @param bool $sheetRead these were the sheet's last rows
     */
    public function __construct(
        public string $importId,
        public int $readAttempt,
        public string $sheetId,
        public array $rows,
        public int $lastColumn,
        public bool $sheetRead,
    ) {
    }
}
