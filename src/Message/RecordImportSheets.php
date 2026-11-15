<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\RequiresFreshEntityManagerState;

/**
 * The file was opened: its sheets, before any row. Answers whether reading goes on.
 *
 * Works on fresh state (RequiresFreshEntityManagerState): the worker's entity manager may still hold the import as it
 * was when the read began - a discard or a new attempt by another process must be seen before anything is written.
 */
readonly final class RecordImportSheets implements RequiresFreshEntityManagerState
{
    /**
     * @param list<array{id: string, position: int, title: string, hidden: bool, hints: null|array<string, mixed>}> $sheets
     */
    public function __construct(
        public string $importId,
        public int $readAttempt,
        public array $sheets,
    ) {
    }
}
