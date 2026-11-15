<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\RequiresFreshEntityManagerState;

/**
 * Rows of a sheet that are equal to another row of it, numbered and linked (ImportEqualRows) - a batch bounded like
 * a batch of parsed rows, written in one short transaction and refused like one when the sheet's mapping moved on.
 * Answers whether the parse goes on.
 */
readonly final class RecordEqualImportRows implements RequiresFreshEntityManagerState
{
    /**
     * @param array<string, array{occurrence: int, sourceKey: null|string, sameAs: null|string}> $rows by row id: which
     *        of its equal rows it is, a result's key with that occurrence, the row it is the same result as
     */
    public function __construct(
        public string $importId,
        public string $sheetId,
        public int $mappingVersion,
        public array $rows,
    ) {
    }
}
