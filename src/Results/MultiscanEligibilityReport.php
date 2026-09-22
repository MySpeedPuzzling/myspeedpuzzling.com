<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * @see \SpeedPuzzling\Web\Services\MultiscanEligibility
 */
readonly final class MultiscanEligibilityReport
{
    /**
     * @param list<string> $eligible Puzzle ids the action applies to, in tray order
     * @param array<string, string> $skipped puzzleId => reason key (`multiscan.reason.<key>`)
     * @param array<string, string> $counterpartyNames puzzleId => name of the person on the other side of an open lend (for reasons and the recap)
     * @param array<string, string> $lentPuzzleIds puzzleId => lent_puzzle id, for the Return action
     */
    public function __construct(
        public array $eligible,
        public array $skipped,
        public array $counterpartyNames,
        public array $lentPuzzleIds,
    ) {
    }

    public function isEligible(string $puzzleId): bool
    {
        return in_array($puzzleId, $this->eligible, true);
    }

    public function reasonFor(string $puzzleId): null|string
    {
        return $this->skipped[$puzzleId] ?? null;
    }
}
