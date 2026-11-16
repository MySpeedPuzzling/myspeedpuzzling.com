<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\ResultsImport;

use DateTimeImmutable;

/**
 * A results source of an organization as its results import page lists it - never the spreadsheet id (the database
 * only, docs/features/event-results-import/README.md "New tables").
 */
readonly final class ResultsImportSource
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $format,
        public DateTimeImmutable $addedAt,
        public null|DateTimeImmutable $lastCheckedAt,
    ) {
    }
}
