<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * What the prune did in object storage, written to the rows (PruneDataImportsHandler - one batch, one short
 * transaction): `removeImportIds` = imports whose object is deleted and that go whole (an upload never confirmed, a
 * discarded import), `fileRemovedImportIds` = imports whose file was kept long enough - they stay, without the file.
 */
readonly final class RecordDataImportsPruned
{
    /**
     * @param list<string> $removeImportIds
     * @param list<string> $fileRemovedImportIds
     */
    public function __construct(
        public array $removeImportIds,
        public array $fileRemovedImportIds,
    ) {
    }
}
