<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * An uploaded participant list after the column mapping (ColumnMapping::toRows()). Travels in the
 * ApplyParticipantImport message, so the handler never reads the file again.
 */
readonly final class ParticipantImportRows
{
    /**
     * @param list<ParticipantImportRowData> $rows in file order, rows with no value at all left out
     * @param list<string> $unmappedHeaders headers of the columns nobody imports (shown as "Not imported")
     * @param bool $roundsMapped a Rounds or Round column is mapped - full sync may take people out of rounds
     */
    public function __construct(
        public array $rows,
        public array $unmappedHeaders = [],
        public bool $roundsMapped = false,
    ) {
    }

    /**
     * Part of the import's fingerprint: the same file with the same mapping gives the same hash.
     */
    public function hash(): string
    {
        return hash('sha256', json_encode([
            array_map(static fn (ParticipantImportRowData $row): array => $row->toArray(), $this->rows),
            $this->roundsMapped,
        ], JSON_THROW_ON_ERROR));
    }
}
