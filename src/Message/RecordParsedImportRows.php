<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\RequiresFreshEntityManagerState;

/**
 * A batch of a sheet's rows as ImportRowParser read them, written in one short transaction. Answers whether parsing
 * goes on - false when the sheet's mapping is not the version these rows were parsed for any more, or the import is
 * not being set up (discarded meanwhile).
 *
 * On fresh state, for every batch: the worker's entity manager must see a mapping another process changed, and none
 * of a sheet's hundred batches may stay in memory.
 */
readonly final class RecordParsedImportRows implements RequiresFreshEntityManagerState
{
    /**
     * @param array<string, array{kind: string, parsed: array<string, mixed>, problems: list<array<string, mixed>>, puzzleKey: null|string, sourceKey: null|string, sourceRowId: null|string, leftOut: bool}> $rows by row id (ParsedImportRow::toRecord())
     */
    public function __construct(
        public string $importId,
        public string $sheetId,
        public int $mappingVersion,
        public array $rows,
    ) {
    }
}
