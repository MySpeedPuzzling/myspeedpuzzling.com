<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What the worker needs to fetch an import's Google sheet - plain values, read once when the fetch begins
 * (BeginImportFetch); nothing of the entity travels through the fetch.
 */
readonly final class ImportFetchJob
{
    public function __construct(
        public string $importId,
        /** What the fetch writes carries it: a fetch that was given up can not write into the next one */
        public int $fetchAttempt,
        public string $spreadsheetId,
        /** Where the file goes in object storage */
        public string $fileKey,
    ) {
    }
}
