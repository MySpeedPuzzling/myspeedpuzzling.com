<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\SuspicionDirection;

/**
 * An import card of the time verification queue (docs/features/suspicious-time-review.md "Import cards"): the
 * pending cases of one direction on results that one "Add" of a player's spreadsheet import made, shown and decided
 * together - with what a moderator needs to judge a history brought in at once.
 */
readonly final class SuspiciousTimeImportCard
{
    /** The imported pace counts as consistent with the typed one within this factor, either way */
    public const float CONSISTENT_WITHIN = 1.5;

    /**
     * @param list<array{case_id: string, time_id: string, puzzle_id: string, puzzle_name: string, pieces: int, seconds: null|int, expected_seconds: null|int, type: string}> $examples
     * @param list<array{pieces: int, imported: int, importedPpm: float, typed: int, typedPpm: null|float}> $paces
     */
    public function __construct(
        /** The "Add" of the import (data_import_batch) */
        public string $batchId,
        public SuspicionDirection $direction,
        public DateTimeImmutable $addedAt,
        public int $rowsAdded,
        public int $resultsAdded,
        /** xlsx, csv … - never the file's name: it is the player's own */
        public string $fileKind,
        public string $playerId,
        public null|string $playerName,
        public string $playerCode,
        public bool $playerPrivate,
        public DateTimeImmutable $registeredAt,
        /** The player's results that count, and how many of them came from spreadsheets */
        public int $resultsTotal,
        public int $resultsImported,
        /** Pending cases of this direction */
        public int $cases,
        /** Pending cases of the same "Add" in the other direction (their own card, on the other tab) */
        public int $otherDirectionCases,
        /** How many times faster (slower) than expected: the least and the most */
        public null|float $factorFrom,
        public null|float $factorTo,
        public array $examples,
        public array $paces,
    ) {
    }

    public function resultsTyped(): int
    {
        return max(0, $this->resultsTotal - $this->resultsImported);
    }

    /**
     * How the imported solo results stand to the player's own typed ones on the piece counts both have:
     * `consistent` (every such piece count within CONSISTENT_WITHIN), `faster` / `slower` (the imported ones are
     * off that way on at least one), `nothing` (no piece count has both).
     */
    public function comparison(): string
    {
        $verdict = 'nothing';

        foreach ($this->paces as $pace) {
            if ($pace['typedPpm'] === null || $pace['typedPpm'] <= 0.0) {
                continue;
            }

            $ratio = $pace['importedPpm'] / $pace['typedPpm'];

            if ($ratio > self::CONSISTENT_WITHIN) {
                return 'faster';
            }

            if ($ratio < 1 / self::CONSISTENT_WITHIN) {
                return 'slower';
            }

            $verdict = 'consistent';
        }

        return $verdict;
    }
}
