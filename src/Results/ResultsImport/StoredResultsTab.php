<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\ResultsImport;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\EventResultsImport\EventTabStatus;
use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * What is stored of one event of a results source (event_results_sheet_tab, keyed by the format's stable key) - the
 * results import page compares a check with it.
 */
readonly final class StoredResultsTab
{
    /**
     * @param list<string> $roundPuzzleIds lower-case
     * @param array<array-key, StoredTeamResult> $results by team key (a numeric key is an int key in PHP)
     */
    public function __construct(
        public string $key,
        public EventTabStatus $status,
        public null|string $contentHash,
        public bool $isDetached,
        public null|DateTimeImmutable $appliedAt,
        public null|string $competitionId,
        public null|string $competitionName,
        public null|string $competitionSlug,
        public null|string $location,
        public null|DateTimeImmutable $dateFrom,
        public null|string $roundId,
        public null|string $roundSlug,
        public null|RoundCategory $roundCategory,
        public null|int $minutesLimit,
        // UTC
        public null|DateTimeImmutable $roundStartsAt,
        public bool $startTimeKnown,
        public null|string $roundTimezone,
        public array $roundPuzzleIds,
        public array $results,
    ) {
    }

    public function isImported(): bool
    {
        return $this->competitionId !== null;
    }
}
