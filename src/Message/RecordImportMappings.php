<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\RequiresFreshEntityManagerState;

/**
 * The mappings the import proposes for its sheets (ProposeImportMappings), stored as version 1 of each sheet that
 * has none - and, in the same transaction, the parsing of every sheet that needs no answer is queued. Answers the
 * ids of the sheets queued.
 *
 * On fresh state: the worker's entity manager may hold the import as it was before a discard.
 */
readonly final class RecordImportMappings implements RequiresFreshEntityManagerState
{
    /**
     * @param array<string, array{mapping: array<string, mixed>, options: array<string, mixed>, valueAnswers: array<int, array<string, string>>, headerSignature: null|string}> $proposals by sheet id
     */
    public function __construct(
        public string $importId,
        public array $proposals,
    ) {
    }
}
