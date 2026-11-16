<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;

/**
 * The log of one import from the results import page (ImportResultsFromCheck).
 */
readonly final class RecordResultsImport
{
    /**
     * @param list<array{key: string, title: string, outcome: string, competition_id: null|string, added: int, updated: int, removed: int}> $events
     */
    public function __construct(
        public string $importId,
        public string $sourceId,
        public string $checkId,
        public string $importedByPlayerId,
        public DateTimeImmutable $importedAt,
        public array $events,
    ) {
    }
}
