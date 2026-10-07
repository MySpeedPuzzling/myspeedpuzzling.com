<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\RoundResultChangeStatus;

/**
 * What RecordRoundResultsHandler returns (read it from the envelope's HandledStamp): one outcome per change, in the
 * order they were sent, and the entries the change set wrote to (nothing on a dry run).
 */
readonly final class RecordedRoundResults
{
    public function __construct(
        /** @var list<RoundResultChangeOutcome> */
        public array $outcomes,
        /** @var list<string> RoundEntryRef strings of the entries written (created or changed) */
        public array $changedEntryRefs,
        public bool $dryRun,
    ) {
    }

    public function countOf(RoundResultChangeStatus $status): int
    {
        return count(array_filter($this->outcomes, static fn (RoundResultChangeOutcome $outcome): bool => $outcome->status === $status));
    }
}
