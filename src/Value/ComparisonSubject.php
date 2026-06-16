<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * A single subject in the comparison: a base player optionally narrowed by
 * required co-solvers (used in pairs/teams modes to require specific partners).
 */
readonly final class ComparisonSubject
{
    public function __construct(
        public string $playerId,
        /** @var list<string> required co-solver player IDs (pairs/teams narrowing) */
        public array $coSolverIds = [],
    ) {
    }

    /**
     * Stable identity for a subject within a single mode: the base player plus
     * the sorted set of required co-solvers.
     */
    public function key(): string
    {
        $ids = $this->coSolverIds;
        sort($ids);

        return $this->playerId . ($ids === [] ? '' : ('+' . implode('+', $ids)));
    }
}
